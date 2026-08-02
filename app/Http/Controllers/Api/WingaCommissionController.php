<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\WingaCommission;
use App\Services\AccountingEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WingaCommissionController extends Controller
{
    public function __construct(
        private AccountingEntryService $entries = new AccountingEntryService()
    ) {}

    private function tenantOwnerId(Request $request): ?int
    {
        if ($ownerId = $request->ownerId()) {
            return $ownerId;
        }

        return $request->user()?->employeeProfile?->branch?->owner_id;
    }

    public function index(Request $request): JsonResponse
    {
        $query = WingaCommission::forOwner($this->tenantOwnerId($request))
            ->with(['winga', 'order']);

        if ($wingaId = $request->query('winga_id')) {
            $query->where('winga_id', $wingaId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($branchId = $request->query('branch_id')) {
            $query->whereHas('winga', fn ($q) => $q->where('branch_id', $branchId));
        }

        $commissions = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($commissions);
    }

    public function summary(Request $request): JsonResponse
    {
        $ownerId = $this->tenantOwnerId($request);

        $pending = WingaCommission::forOwner($ownerId)
            ->where('status', 'pending')
            ->selectRaw('COUNT(*) as count, SUM(commission_amount) as gross, SUM(withholding_tax) as tax, SUM(net_amount) as net')
            ->first();

        $paid = WingaCommission::forOwner($ownerId)
            ->where('status', 'paid')
            ->selectRaw('COUNT(*) as count, SUM(commission_amount) as gross, SUM(withholding_tax) as tax, SUM(net_amount) as net')
            ->first();

        $byWinga = WingaCommission::forOwner($ownerId)
            ->where('status', 'pending')
            ->with('winga')
            ->selectRaw('winga_id, COUNT(*) as count, SUM(commission_amount) as gross, SUM(withholding_tax) as tax, SUM(net_amount) as net')
            ->groupBy('winga_id')
            ->get()
            ->map(function ($row) {
                return [
                    'winga_id' => $row->winga_id,
                    'winga_name' => $row->winga->name ?? 'Unknown',
                    'count' => (int) $row->count,
                    'gross' => (float) $row->gross,
                    'tax' => (float) $row->tax,
                    'net' => (float) $row->net,
                ];
            });

        return response()->json([
            'pending' => [
                'count' => (int) ($pending->count ?? 0),
                'gross' => (float) ($pending->gross ?? 0),
                'tax' => (float) ($pending->tax ?? 0),
                'net' => (float) ($pending->net ?? 0),
            ],
            'paid' => [
                'count' => (int) ($paid->count ?? 0),
                'gross' => (float) ($paid->gross ?? 0),
                'tax' => (float) ($paid->tax ?? 0),
                'net' => (float) ($paid->net ?? 0),
            ],
            'by_winga' => $byWinga,
        ]);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $commission = WingaCommission::forOwner($request->ownerId())
            ->where('id', $id)
            ->where('status', 'pending')
            ->with(['winga', 'order'])
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $journalEntry = $this->postPayoutEntry($user, $this->tenantOwnerId($request), collect([$commission]));

            $commission->update([
                'status' => 'paid',
                'paid_at' => now(),
                'journal_entry_id' => $journalEntry?->id,
            ]);

            DB::commit();

            return response()->json([
                'commission' => $commission->fresh(['winga', 'order']),
                'message' => 'Winga commission paid successfully',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to process payment'], 500);
        }
    }

    public function payAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $pending = WingaCommission::forOwner($request->ownerId())
            ->where('status', 'pending')
            ->with(['winga', 'order'])
            ->get();

        if ($pending->isEmpty()) {
            return response()->json(['message' => 'No pending winga commissions']);
        }

        DB::beginTransaction();

        try {
            $journalEntry = $this->postPayoutEntry($user, $this->tenantOwnerId($request), $pending);

            foreach ($pending as $commission) {
                $commission->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'journal_entry_id' => $journalEntry?->id,
                ]);
            }

            DB::commit();

            $gross = $pending->sum('commission_amount');
            $net = $pending->sum('net_amount');
            $tax = $pending->sum('withholding_tax');

            return response()->json([
                'message' => "Paid {$pending->count()} winga commissions totaling TSh " . number_format($net) . " (TSh " . number_format($tax) . ' withheld for TRA TDS)',
                'count' => $pending->count(),
                'gross' => round($gross, 2),
                'net' => round($net, 2),
                'tax' => round($tax, 2),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json(['message' => 'Failed to process bulk payment'], 500);
        }
    }

    /**
     * Post a single payout journal entry: release the accrued payable, pay the net
     * amount from cash, and accrue the TRA withholding tax payable.
     */
    private function postPayoutEntry($user, int $ownerId, $commissions): ?JournalEntry
    {
        $gross = $commissions->sum('commission_amount');
        $net = $commissions->sum('net_amount');
        $tax = $commissions->sum('withholding_tax');

        $payable = Account::where('owner_id', $ownerId)->where('code', '2100')->first();
        $whtPayable = Account::where('owner_id', $ownerId)->where('code', '2120')->first();
        $cash = Account::where('owner_id', $ownerId)->where('code', '1020')->first();

        if (!$payable || !$whtPayable || !$cash) {
            throw new \RuntimeException('Winga payout accounts are not configured.');
        }

        $entry = JournalEntry::create([
            'owner_id' => $ownerId,
            'reference' => JournalEntry::generateReference($user->id),
            'date' => now()->toDateString(),
            'description' => 'Winga commission payout (' . $commissions->count() . ' commission' . ($commissions->count() === 1 ? '' : 's') . ')',
            'status' => 'posted',
            'posted_by' => $user->id,
            'posted_at' => now(),
        ]);

        $entry->lines()->create([
            'account_id' => $payable->id,
            'debit' => $gross,
            'credit' => 0,
            'description' => 'Winga commission payable settlement',
        ]);

        $entry->lines()->create([
            'account_id' => $cash->id,
            'debit' => 0,
            'credit' => $net,
            'description' => 'Winga commission net payout',
        ]);

        if ($tax > 0) {
            $entry->lines()->create([
                'account_id' => $whtPayable->id,
                'debit' => 0,
                'credit' => $tax,
                'description' => 'TRA withholding tax (TDS) withheld on winga commissions',
            ]);
        }

        return $entry;
    }
}
