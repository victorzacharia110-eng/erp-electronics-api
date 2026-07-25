<?php

namespace App\Services;

use App\Models\Account;
use App\Models\AccountingReport;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AccountingReportService
{
    public function generateMonthlyReport(User $owner, int $year, int $month): AccountingReport
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();
        $label = $start->format('F Y');

        $trialBalance = $this->computeTrialBalance($owner, $end->toDateString());
        $profitLoss = $this->computeProfitLoss($owner, $start->toDateString(), $end->toDateString());
        $balanceSheet = $this->computeBalanceSheet($owner, $end->toDateString());

        $data = [
            'trial_balance' => $trialBalance,
            'profit_and_loss' => $profitLoss,
            'balance_sheet' => $balanceSheet,
            'journal_summary' => $this->journalSummary($owner, $start, $end),
        ];

        $summary = [
            'total_revenue' => $profitLoss['total_revenue'],
            'total_expenses' => $profitLoss['total_expenses'],
            'net_income' => $profitLoss['net_income'],
            'total_assets' => $balanceSheet['total_assets'],
            'total_liabilities' => $balanceSheet['total_liabilities'],
            'total_equity' => $balanceSheet['total_equity'],
            'trial_balance_matched' => $trialBalance['is_balanced'],
            'entries_count' => JournalEntry::where('owner_id', $owner->id)
                ->where('status', 'posted')
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->count(),
        ];

        return AccountingReport::updateOrCreate(
            ['owner_id' => $owner->id, 'report_type' => 'monthly', 'period_start' => $start->toDateString()],
            [
                'period_label' => $label,
                'period_end' => $end->toDateString(),
                'data' => $data,
                'summary' => $summary,
                'is_finalized' => true,
            ]
        );
    }

    public function generateYearlyReport(User $owner, int $year): AccountingReport
    {
        $start = Carbon::create($year, 1, 1)->startOfYear();
        $end = $start->copy()->endOfYear();
        $label = (string) $year;

        $profitLoss = $this->computeProfitLoss($owner, $start->toDateString(), $end->toDateString());
        $balanceSheet = $this->computeBalanceSheet($owner, $end->toDateString());
        $trialBalance = $this->computeTrialBalance($owner, $end->toDateString());

        $monthlyBreakdown = [];
        for ($m = 1; $m <= 12; $m++) {
            $mStart = Carbon::create($year, $m, 1)->startOfMonth();
            $mEnd = $mStart->copy()->endOfMonth();
            $mPL = $this->computeProfitLoss($owner, $mStart->toDateString(), $mEnd->toDateString());
            $monthlyBreakdown[] = [
                'month' => $mStart->format('M'),
                'revenue' => $mPL['total_revenue'],
                'expenses' => $mPL['total_expenses'],
                'net_income' => $mPL['net_income'],
            ];
        }

        $data = [
            'trial_balance' => $trialBalance,
            'profit_and_loss' => $profitLoss,
            'balance_sheet' => $balanceSheet,
            'monthly_breakdown' => $monthlyBreakdown,
            'journal_summary' => $this->journalSummary($owner, $start, $end),
        ];

        $summary = [
            'total_revenue' => $profitLoss['total_revenue'],
            'total_expenses' => $profitLoss['total_expenses'],
            'net_income' => $profitLoss['net_income'],
            'total_assets' => $balanceSheet['total_assets'],
            'total_liabilities' => $balanceSheet['total_liabilities'],
            'total_equity' => $balanceSheet['total_equity'],
            'trial_balance_matched' => $trialBalance['is_balanced'],
            'entries_count' => JournalEntry::where('owner_id', $owner->id)
                ->where('status', 'posted')
                ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                ->count(),
            'avg_monthly_revenue' => $profitLoss['total_revenue'] / 12,
            'avg_monthly_expenses' => $profitLoss['total_expenses'] / 12,
            'profit_margin' => $profitLoss['total_revenue'] > 0
                ? round(($profitLoss['net_income'] / $profitLoss['total_revenue']) * 100, 2)
                : 0,
        ];

        return AccountingReport::updateOrCreate(
            ['owner_id' => $owner->id, 'report_type' => 'yearly', 'period_start' => $start->toDateString()],
            [
                'period_label' => $label,
                'period_end' => $end->toDateString(),
                'data' => $data,
                'summary' => $summary,
                'is_finalized' => true,
            ]
        );
    }

    public function computeTrialBalance(User $owner, string $asOf): array
    {
        $accounts = Account::where('owner_id', $owner->id)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $results = [];
        $totalDebit = 0;
        $totalCredit = 0;

        foreach ($accounts as $account) {
            $balance = JournalLine::whereHas('journalEntry', function ($q) use ($owner, $asOf) {
                $q->where('owner_id', $owner->id)
                    ->where('status', 'posted')
                    ->where('date', '<=', $asOf);
            })
                ->where('account_id', $account->id)
                ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
                ->first();

            $debit = (float) ($balance->total_debit ?? 0);
            $credit = (float) ($balance->total_credit ?? 0);

            if ($account->normal_balance === 'debit') {
                $net = $debit - $credit;
                $netDebit = $net >= 0 ? $net : 0;
                $netCredit = $net < 0 ? abs($net) : 0;
            } else {
                $net = $credit - $debit;
                $netCredit = $net >= 0 ? $net : 0;
                $netDebit = $net < 0 ? abs($net) : 0;
            }

            if ($netDebit > 0 || $netCredit > 0) {
                $results[] = [
                    'account' => [
                        'id' => $account->id,
                        'code' => str_pad($account->code, 4, '0', STR_PAD_LEFT),
                        'name' => $account->name,
                        'type' => $account->type,
                    ],
                    'debit' => round($netDebit, 2),
                    'credit' => round($netCredit, 2),
                ];
            }

            $totalDebit += $netDebit;
            $totalCredit += $netCredit;
        }

        return [
            'as_of' => $asOf,
            'accounts' => $results,
            'total_debit' => round($totalDebit, 2),
            'total_credit' => round($totalCredit, 2),
            'is_balanced' => abs($totalDebit - $totalCredit) < 0.01,
        ];
    }

    public function computeProfitLoss(User $owner, string $from, string $to): array
    {
        $accounts = Account::where('owner_id', $owner->id)
            ->where('is_active', true)
            ->whereIn('type', ['revenue', 'expense'])
            ->orderBy('code')
            ->get();

        $revenue = [];
        $expenses = [];
        $totalRevenue = 0;
        $totalExpenses = 0;

        foreach ($accounts as $account) {
            $balance = JournalLine::whereHas('journalEntry', function ($q) use ($owner, $from, $to) {
                $q->where('owner_id', $owner->id)
                    ->where('status', 'posted')
                    ->whereBetween('date', [$from, $to]);
            })
                ->where('account_id', $account->id)
                ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
                ->first();

            $debit = (float) ($balance->total_debit ?? 0);
            $credit = (float) ($balance->total_credit ?? 0);

            $amount = $account->type === 'revenue' ? $credit - $debit : $debit - $credit;

            if (abs($amount) > 0.01) {
                $item = [
                    'id' => $account->id,
                    'code' => str_pad($account->code, 4, '0', STR_PAD_LEFT),
                    'name' => $account->name,
                    'amount' => round($amount, 2),
                ];

                if ($account->type === 'revenue') {
                    $revenue[] = $item;
                    $totalRevenue += $amount;
                } else {
                    $expenses[] = $item;
                    $totalExpenses += $amount;
                }
            }
        }

        return [
            'from' => $from,
            'to' => $to,
            'revenue' => $revenue,
            'total_revenue' => round($totalRevenue, 2),
            'expenses' => $expenses,
            'total_expenses' => round($totalExpenses, 2),
            'net_income' => round($totalRevenue - $totalExpenses, 2),
        ];
    }

    public function computeBalanceSheet(User $owner, string $asOf): array
    {
        $accounts = Account::where('owner_id', $owner->id)
            ->where('is_active', true)
            ->whereIn('type', ['asset', 'liability', 'equity'])
            ->orderBy('code')
            ->get();

        $assets = [];
        $liabilities = [];
        $equity = [];
        $totalAssets = 0;
        $totalLiabilities = 0;
        $totalEquity = 0;

        foreach ($accounts as $account) {
            $balance = JournalLine::whereHas('journalEntry', function ($q) use ($owner, $asOf) {
                $q->where('owner_id', $owner->id)
                    ->where('status', 'posted')
                    ->where('date', '<=', $asOf);
            })
                ->where('account_id', $account->id)
                ->selectRaw('SUM(debit) as total_debit, SUM(credit) as total_credit')
                ->first();

            $debit = (float) ($balance->total_debit ?? 0);
            $credit = (float) ($balance->total_credit ?? 0);

            if ($account->normal_balance === 'debit') {
                $amount = $debit - $credit;
            } else {
                $amount = $credit - $debit;
            }

            if (abs($amount) < 0.01) continue;

            $item = [
                'id' => $account->id,
                'code' => str_pad($account->code, 4, '0', STR_PAD_LEFT),
                'name' => $account->name,
                'amount' => round($amount, 2),
            ];

            match ($account->type) {
                'asset' => ($assets[] = $item) && ($totalAssets += $amount),
                'liability' => ($liabilities[] = $item) && ($totalLiabilities += $amount),
                'equity' => ($equity[] = $item) && ($totalEquity += $amount),
            };
        }

        return [
            'as_of' => $asOf,
            'assets' => $assets,
            'total_assets' => round($totalAssets, 2),
            'liabilities' => $liabilities,
            'total_liabilities' => round($totalLiabilities, 2),
            'equity' => $equity,
            'total_equity' => round($totalEquity, 2),
            'is_balanced' => abs($totalAssets - ($totalLiabilities + $totalEquity)) < 0.01,
        ];
    }

    private function journalSummary(User $owner, Carbon $start, Carbon $end): array
    {
        $entries = JournalEntry::where('owner_id', $owner->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        return [
            'total_entries' => $entries->count(),
            'posted' => $entries->where('status', 'posted')->count(),
            'drafts' => $entries->where('status', 'draft')->count(),
            'voided' => $entries->where('status', 'voided')->count(),
            'total_debit' => round($entries->where('status', 'posted')->sum(fn($e) => $e->total_debit), 2),
            'total_credit' => round($entries->where('status', 'posted')->sum(fn($e) => $e->total_credit), 2),
        ];
    }
}
