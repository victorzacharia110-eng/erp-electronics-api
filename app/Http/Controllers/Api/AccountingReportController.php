<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingReport;
use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\AiSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingReportController extends Controller
{
    public function __construct(
        private AccountingReportService $reportService = new AccountingReportService(),
        private AiSuggestionService $suggestionService = new AiSuggestionService()
    ) {}

    public function trialBalance(Request $request): JsonResponse
    {
        $user = $request->user();
        $asOf = $request->query('as_of', date('Y-m-d'));
        $result = $this->reportService->computeTrialBalance($this->resolveOwner($user), $asOf);

        return response()->json($result);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $user = $request->user();
        $from = $request->query('from', date('Y-m-01'));
        $to = $request->query('to', date('Y-m-t'));
        $result = $this->reportService->computeProfitLoss($this->resolveOwner($user), $from, $to);

        return response()->json($result);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $user = $request->user();
        $asOf = $request->query('as_of', date('Y-m-d'));
        $result = $this->reportService->computeBalanceSheet($this->resolveOwner($user), $asOf);

        return response()->json($result);
    }

    public function generalLedger(Request $request): JsonResponse
    {
        $user = $request->user();
        $owner = $this->resolveOwner($user);
        $accountId = $request->query('account_id');

        if (!$accountId) {
            return response()->json(['message' => 'account_id is required'], 422);
        }

        $account = \App\Models\Account::where('id', $accountId)
            ->where('owner_id', $owner->id)
            ->firstOrFail();

        $from = $request->query('from');
        $to = $request->query('to');

        $query = \App\Models\JournalLine::where('account_id', $accountId)
            ->whereHas('journalEntry', function ($q) use ($owner, $from, $to) {
                $q->where('owner_id', $owner->id)
                    ->where('status', 'posted');
                if ($from) $q->where('date', '>=', $from);
                if ($to) $q->where('date', '<=', $to);
            })
            ->with('journalEntry')
            ->orderBy('id');

        $lines = $query->get();

        $runningBalance = 0;
        $ledger = $lines->map(function ($line) use (&$runningBalance, $account) {
            if ($account->normal_balance === 'debit') {
                $runningBalance += (float) $line->debit - (float) $line->credit;
            } else {
                $runningBalance += (float) $line->credit - (float) $line->debit;
            }

            return [
                'id' => $line->id,
                'date' => $line->journalEntry->date,
                'reference' => $line->journalEntry->reference,
                'description' => $line->description ?? $line->journalEntry->description,
                'debit' => (float) $line->debit,
                'credit' => (float) $line->credit,
                'balance' => round($runningBalance, 2),
            ];
        });

        return response()->json([
            'account' => [
                'id' => $account->id,
                'code' => str_pad($account->code, 4, '0', STR_PAD_LEFT),
                'name' => $account->name,
                'type' => $account->type,
            ],
            'from' => $from,
            'to' => $to,
            'entries' => $ledger,
            'closing_balance' => round($runningBalance, 2),
        ]);
    }

    public function generateMonthly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:' . (date('Y') + 1),
            'month' => 'required|integer|between:1,12',
        ]);

        $owner = $this->resolveOwner($request->user());
        $report = $this->reportService->generateMonthlyReport($owner, $validated['year'], $validated['month']);

        return response()->json([
            'message' => 'Monthly report generated',
            'report' => $report,
        ]);
    }

    public function generateYearly(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:' . (date('Y') + 1),
        ]);

        $owner = $this->resolveOwner($request->user());
        $report = $this->reportService->generateYearlyReport($owner, $validated['year']);

        return response()->json([
            'message' => 'Yearly report generated',
            'report' => $report,
        ]);
    }

    public function listReports(Request $request): JsonResponse
    {
        $owner = $this->resolveOwner($request->user());
        $type = $request->query('type');

        $query = AccountingReport::where('owner_id', $owner->id)
            ->orderByDesc('period_start');

        if ($type) {
            $query->where('report_type', $type);
        }

        $reports = $query->paginate(15);

        return response()->json($reports);
    }

    public function showReport(Request $request, int $id): JsonResponse
    {
        $owner = $this->resolveOwner($request->user());
        $report = AccountingReport::where('owner_id', $owner->id)->findOrFail($id);

        return response()->json($report);
    }

    public function aiSuggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report_id' => 'required|exists:accounting_reports,id',
        ]);

        $owner = $this->resolveOwner($request->user());
        $report = AccountingReport::where('owner_id', $owner->id)->findOrFail($validated['report_id']);

        $result = $this->suggestionService->generate($report);

        $report->update([
            'suggestions' => $result['suggestions'],
            'suggestions_source' => $result['source'],
            'suggestions_generated_at' => now(),
        ]);

        return response()->json([
            'suggestions' => $result['suggestions'],
            'source' => $result['source'],
        ]);
    }

    private function resolveOwner(User $user): User
    {
        if ($user->isOwner()) {
            $tenantOwnerId = \App\Support\Tenant::ownerId(request());

            if ($tenantOwnerId) {
                return User::findOrFail($tenantOwnerId);
            }

            return $user;
        }

        if ($user->isEmployee() && $user->employeeProfile && $user->employeeProfile->branch) {
            $branchOwner = $user->employeeProfile->branch->owner;
            if ($branchOwner) {
                return $branchOwner;
            }
        }

        return $user;
    }
}
