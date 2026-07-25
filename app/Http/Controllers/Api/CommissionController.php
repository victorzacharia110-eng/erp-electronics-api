<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Commission;
use App\Models\JournalEntry;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CommissionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Commission::where('owner_id', $user->id)
            ->with(['employee', 'order']);

        if ($employeeId = $request->query('employee_id')) {
            $query->where('employee_id', $employeeId);
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $commissions = $query->orderBy('created_at', 'desc')->paginate(20);
        return response()->json($commissions);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $request->user();

        $employees = User::where('role', 'employee')
            ->where('is_active', true)
            ->with('employeeProfile')
            ->get();

        $summaries = $employees->map(function ($employee) {
            $pending = Commission::where('employee_id', $employee->id)
                ->where('status', 'pending')
                ->selectRaw('COUNT(*) as count, SUM(commission_amount) as total')
                ->first();

            $paid = Commission::where('employee_id', $employee->id)
                ->where('status', 'paid')
                ->selectRaw('COUNT(*) as count, SUM(commission_amount) as total')
                ->first();

            $totalProfit = Commission::where('employee_id', $employee->id)
                ->selectRaw('SUM(profit_amount) as total')
                ->first();

            return [
                'employee' => [
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'email' => $employee->email,
                    'commission_rate' => $employee->employeeProfile?->commission_rate ?? 0,
                ],
                'pending_count' => $pending->count ?? 0,
                'pending_total' => (float) ($pending->total ?? 0),
                'paid_count' => $paid->count ?? 0,
                'paid_total' => (float) ($paid->total ?? 0),
                'total_profit' => (float) ($totalProfit->total ?? 0),
            ];
        });

        return response()->json($summaries);
    }

    public function pay(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $commission = Commission::where('id', $id)
            ->where('owner_id', $user->id)
            ->where('status', 'pending')
            ->firstOrFail();

        DB::beginTransaction();

        try {
            $cashAccount = Account::where('owner_id', $user->id)->where('code', '1020')->first();
            $commissionExpense = Account::where('owner_id', $user->id)->where('code', '5050')->first();

            $journalEntry = null;
            if ($cashAccount && $commissionExpense) {
                $journalEntry = JournalEntry::create([
                    'owner_id' => $user->id,
                    'reference' => JournalEntry::generateReference($user->id),
                    'date' => now()->toDateString(),
                    'description' => "Commission payout to {$commission->employee->name}",
                    'status' => 'posted',
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                ]);

                $journalEntry->lines()->create([
                    'account_id' => $commissionExpense->id,
                    'debit' => $commission->commission_amount,
                    'credit' => 0,
                    'description' => "Commission for order {$commission->order->order_number}",
                ]);

                $journalEntry->lines()->create([
                    'account_id' => $cashAccount->id,
                    'debit' => 0,
                    'credit' => $commission->commission_amount,
                    'description' => "Commission payout to {$commission->employee->name}",
                ]);
            }

            $commission->update([
                'status' => 'paid',
                'paid_at' => now(),
                'journal_entry_id' => $journalEntry?->id,
            ]);

            DB::commit();

            $commission->load(['employee', 'order']);

            return response()->json([
                'commission' => $commission,
                'message' => 'Commission paid successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to process payment'], 500);
        }
    }

    public function payAll(Request $request): JsonResponse
    {
        $user = $request->user();
        $pending = Commission::where('owner_id', $user->id)->where('status', 'pending')->get();

        if ($pending->isEmpty()) {
            return response()->json(['message' => 'No pending commissions']);
        }

        DB::beginTransaction();

        try {
            $total = $pending->sum('commission_amount');
            $cashAccount = Account::where('owner_id', $user->id)->where('code', '1020')->first();
            $commissionExpense = Account::where('owner_id', $user->id)->where('code', '5050')->first();

            $journalEntry = null;
            if ($cashAccount && $commissionExpense) {
                $journalEntry = JournalEntry::create([
                    'owner_id' => $user->id,
                    'reference' => JournalEntry::generateReference($user->id),
                    'date' => now()->toDateString(),
                    'description' => "Bulk commission payout ({$pending->count()} employees)",
                    'status' => 'posted',
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                ]);

                $journalEntry->lines()->create([
                    'account_id' => $commissionExpense->id,
                    'debit' => $total,
                    'credit' => 0,
                    'description' => 'Bulk commission payout',
                ]);

                $journalEntry->lines()->create([
                    'account_id' => $cashAccount->id,
                    'debit' => 0,
                    'credit' => $total,
                    'description' => 'Bulk commission payout',
                ]);
            }

            foreach ($pending as $commission) {
                $commission->update([
                    'status' => 'paid',
                    'paid_at' => now(),
                    'journal_entry_id' => $journalEntry?->id,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => "Paid {$pending->count()} commissions totaling TSh " . number_format($total),
                'count' => $pending->count(),
                'total' => $total,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to process bulk payment'], 500);
        }
    }

    public function employeeEarnings(Request $request): JsonResponse
    {
        $user = $request->user();

        $totalPending = Commission::where('employee_id', $user->id)->where('status', 'pending')->sum('commission_amount');
        $totalPaid = Commission::where('employee_id', $user->id)->where('status', 'paid')->sum('commission_amount');
        $totalProfit = Commission::where('employee_id', $user->id)->sum('profit_amount');
        $totalOrders = Commission::where('employee_id', $user->id)->count();

        $recent = Commission::where('employee_id', $user->id)
            ->with('order')
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        return response()->json([
            'pending_total' => $totalPending,
            'paid_total' => $totalPaid,
            'total_profit' => $totalProfit,
            'total_orders' => $totalOrders,
            'recent' => $recent,
        ]);
    }
}
