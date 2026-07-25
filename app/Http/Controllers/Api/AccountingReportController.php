<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountingReport;
use App\Models\User;
use App\Services\AccountingReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AccountingReportController extends Controller
{
    public function __construct(
        private AccountingReportService $reportService = new AccountingReportService()
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

        $prompt = $this->buildBilingualPrompt($report);

        try {
            $response = Http::withHeaders([
                'Content-Type' => 'application/json',
            ])->timeout(60)->post(
                'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . config('services.gemini.key'),
                [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => $prompt],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'temperature' => 0.7,
                        'maxOutputTokens' => 4096,
                    ],
                ]
            );

            if ($response->failed()) {
                return response()->json([
                    'suggestions' => $this->getFallbackSuggestions($report),
                    'source' => 'fallback',
                ]);
            }

            $body = $response->json();
            $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? '';

            $suggestions = $this->parseSuggestions($text, $report);

            return response()->json([
                'suggestions' => $suggestions,
                'source' => 'ai',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'suggestions' => $this->getFallbackSuggestions($report),
                'source' => 'fallback',
            ]);
        }
    }

    private function resolveOwner(User $user): User
    {
        if ($user->isOwner()) {
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

    private function buildBilingualPrompt(AccountingReport $report): string
    {
        $data = $report->data;
        $summary = $report->summary;

        $pl = $data['profit_and_loss'] ?? [];
        $bs = $data['balance_sheet'] ?? [];
        $tb = $data['trial_balance'] ?? [];
        $journal = $data['journal_summary'] ?? [];

        $revenueLines = '';
        foreach ($pl['revenue'] ?? [] as $r) {
            $revenueLines .= "- {$r['code']} {$r['name']}: TSh " . number_format($r['amount']) . "\n";
        }

        $expenseLines = '';
        foreach ($pl['expenses'] ?? [] as $e) {
            $expenseLines .= "- {$e['code']} {$e['name']}: TSh " . number_format($e['amount']) . "\n";
        }

        $assetLines = '';
        foreach ($bs['assets'] ?? [] as $a) {
            $assetLines .= "- {$a['code']} {$a['name']}: TSh " . number_format($a['amount']) . "\n";
        }

        $liabilityLines = '';
        foreach ($bs['liabilities'] ?? [] as $l) {
            $liabilityLines .= "- {$l['code']} {$l['name']}: TSh " . number_format($l['amount']) . "\n";
        }

        $journalTotal = $journal['total_entries'] ?? 0;
        $journalPosted = $journal['posted'] ?? 0;
        $journalDrafts = $journal['drafts'] ?? 0;
        $journalVoided = $journal['voided'] ?? 0;
        $plTotalRevenue = $pl['total_revenue'] ?? 0;
        $plTotalExpenses = $pl['total_expenses'] ?? 0;
        $plNetIncome = $pl['net_income'] ?? 0;
        $bsTotalAssets = $bs['total_assets'] ?? 0;
        $bsTotalLiabilities = $bs['total_liabilities'] ?? 0;
        $bsTotalEquity = $bs['total_equity'] ?? 0;
        $bsBalanced = ($bs['is_balanced'] ?? false) ? 'Yes' : 'No';
        $tbTotalDebit = $tb['total_debit'] ?? 0;
        $tbTotalCredit = $tb['total_credit'] ?? 0;
        $tbBalanced = ($tb['is_balanced'] ?? false) ? 'Yes' : 'No';

        $prompt = "You are a certified accountant and financial analyst specializing in Tanzanian small business accounting using double-entry bookkeeping. Analyze the following {$report->report_type} accounting report for the period {$report->period_label} and provide professional financial suggestions.\n\n";

        $prompt .= "ACCOUNTING REPORT DATA:\n";
        $prompt .= "Report Type: " . ucfirst($report->report_type) . "\n";
        $prompt .= "Period: {$report->period_label}\n";
        $prompt .= "Journal Entries: {$journalTotal} (Posted: {$journalPosted}, Drafts: {$journalDrafts}, Voided: {$journalVoided})\n\n";

        $prompt .= "PROFIT & LOSS STATEMENT:\n";
        $prompt .= "Revenue:\n{$revenueLines}";
        $prompt .= "Total Revenue: TSh " . number_format($plTotalRevenue) . "\n";
        $prompt .= "Expenses:\n{$expenseLines}";
        $prompt .= "Total Expenses: TSh " . number_format($plTotalExpenses) . "\n";
        $prompt .= "Net Income: TSh " . number_format($plNetIncome) . "\n\n";

        $prompt .= "BALANCE SHEET:\n";
        $prompt .= "Assets:\n{$assetLines}";
        $prompt .= "Total Assets: TSh " . number_format($bsTotalAssets) . "\n";
        $prompt .= "Liabilities:\n{$liabilityLines}";
        $prompt .= "Total Liabilities: TSh " . number_format($bsTotalLiabilities) . "\n";
        $prompt .= "Total Equity: TSh " . number_format($bsTotalEquity) . "\n";
        $prompt .= "Balance Sheet Balanced: {$bsBalanced}\n\n";

        $prompt .= "TRIAL BALANCE:\n";
        $prompt .= "Total Debit: TSh " . number_format($tbTotalDebit) . "\n";
        $prompt .= "Total Credit: TSh " . number_format($tbTotalCredit) . "\n";
        $prompt .= "Balanced: {$tbBalanced}\n\n";

        $prompt .= "Provide 6-8 professional accounting suggestions. Each suggestion MUST have both Swahili and English versions.\n";
        $prompt .= "Return ONLY valid JSON in this exact format:\n";
        $prompt .= '[{"title_sw": "Title ya Kiswahili", "title_en": "English Title", "description_sw": "Maelezo ya Kiswahili...", "description_en": "English description...", "priority": "high/medium/low", "category": "revenue/expenses/compliance/cash_flow/inventory/tax/growth", "impact": "positive/negative/neutral"}]\n\n';

        $prompt .= "Focus on:\n";
        $prompt .= "1. Revenue analysis and growth opportunities (uchambuzi wa mapato)\n";
        $prompt .= "2. Expense optimization (kupunguza gharama)\n";
        $prompt .= "3. Cash flow management (usimamizi wa mtiririko wa pesa)\n";
        $prompt .= "4. Tax compliance for Tanzanian businesses (ulinganifu wa kodi)\n";
        $prompt .= "5. Double-entry accuracy checks (ukaguzi wa uhasibu wa mkataba)\n";
        $prompt .= "6. Profitability improvements (kuboresha faida)\n";
        $prompt .= "7. Balance sheet health (afya ya hati ya mizani)\n";
        $prompt .= "8. Inventory and asset management (usimamizi wa bidhaa)\n\n";

        $prompt .= "Consider Tanzania-specific context: TRA tax requirements, VAT obligations, mobile money accounting (M-Pesa reconciliation), import duty implications, and TSh currency. For Swahili, use professional accounting terminology (mapato, gharama, faida, deni, haki, mtiririko wa pesa, kodi ya mapato, ada ya mzunguko).\n";

        return $prompt;
    }

    private function parseSuggestions(string $text, AccountingReport $report): array
    {
        $text = trim($text);
        $cleaned = '';
        $len = strlen($text);
        $i = 0;
        while ($i < $len) {
            if ($i + 2 < $len && $text[$i] === '`' && $text[$i + 1] === '`' && $text[$i + 2] === '`') {
                $i += 3;
                while ($i < $len && $text[$i] !== '`') {
                    $i++;
                }
                if ($i < $len) $i++;
                continue;
            }
            $cleaned .= $text[$i];
            $i++;
        }
        $text = trim($cleaned);

        $decoded = json_decode($text, true);

        if (is_array($decoded)) {
            return array_map(function ($s) {
                return [
                    'title_sw' => $s['title_sw'] ?? $s['title'] ?? '',
                    'title_en' => $s['title_en'] ?? $s['title'] ?? '',
                    'description_sw' => $s['description_sw'] ?? $s['description'] ?? '',
                    'description_en' => $s['description_en'] ?? $s['description'] ?? '',
                    'priority' => $s['priority'] ?? 'medium',
                    'category' => $s['category'] ?? 'general',
                    'impact' => $s['impact'] ?? 'neutral',
                ];
            }, $decoded);
        }

        return $this->getFallbackSuggestions($report);
    }

    private function getFallbackSuggestions(AccountingReport $report): array
    {
        $summary = $report->summary;
        $netIncome = $summary['net_income'] ?? 0;
        $totalRevenue = $summary['total_revenue'] ?? 0;
        $totalExpenses = $summary['total_expenses'] ?? 0;
        $isBalanced = $summary['trial_balance_matched'] ?? false;

        $suggestions = [];

        if ($netIncome < 0) {
            $suggestions[] = [
                'title_sw' => 'Faida ni Hasi - Hatari ya Kifedha',
                'title_en' => 'Net Loss - Financial Risk',
                'description_sw' => "Kampuna imepata hasia ya TSh " . number_format(abs($netIncome)) . " katika kipindi hiki. Lazima kupunguza gharama zisizo za lazima au kuongeza mapato ili kuepuka hasara zaidi. Angalia gharama za bidhaa, kodi, na matumizi mengine.",
                'description_en' => "The business incurred a net loss of TSh " . number_format(abs($netIncome)) . " this period. Non-essential expenses must be reduced and revenue increased to avoid further losses. Review COGS, rent, and other operating costs.",
                'priority' => 'high',
                'category' => 'expenses',
                'impact' => 'negative',
            ];
        } else {
            $margin = $totalRevenue > 0 ? round(($netIncome / $totalRevenue) * 100, 1) : 0;
            $suggestions[] = [
                'title_sw' => "Viwango vya Faida: {$margin}%",
                'title_en' => "Profit Margin: {$margin}%",
                'description_sw' => $margin < 15
                    ? "Viwango vya faida ni vichache sana kwa biashara ya vifaa vya elektroniki. Viwango vya kawaida ni 20-35%. Angalia upya bei na gharama za ununuzi."
                    : "Viwango vya faida ni vya kukubalika. Endelea kufuatilia na kuboresha kupitia upimaji wa bei na kupunguza gharama za manunuzi.",
                'description_en' => $margin < 15
                    ? "Profit margin is too low for electronics retail. Industry benchmarks are 20-35%. Review pricing strategy and procurement costs."
                    : "Profit margin is acceptable. Continue monitoring and improve through pricing optimization and procurement cost reduction.",
                'priority' => $margin < 15 ? 'high' : 'medium',
                'category' => 'revenue',
                'impact' => $margin < 15 ? 'negative' : 'positive',
            ];
        }

        $suggestions[] = [
            'title_sw' => 'Urekebishaji wa M-Pesa',
            'title_en' => 'M-Pesa Reconciliation',
            'description_sw' => "Fanya ukaguzi wa m-Pesa kila siku dhidi ya rekodi za mauzo. Hakikisha kila muamala wa m-Pesa unaingia kwenye mpango wa hesabu kwa usahihi. Tumia Akaunti ya M-Pesa (1020) kwa miamala yote ya simu.",
            'description_en' => "Perform daily M-Pesa reconciliation against sales records. Ensure every mobile money transaction is properly recorded in the accounting system. Use M-Pesa Account (1020) for all mobile money transactions.",
            'priority' => 'high',
            'category' => 'cash_flow',
            'impact' => 'neutral',
        ];

        if (!$isBalanced) {
            $suggestions[] = [
                'title_sw' => 'Mizani haifanani - Hitaji la Urekebishaji',
                'title_en' => 'Trial Balance Mismatch - Correction Needed',
                'description_sw' => "Mizani ya jaribio haifanani. Kuna makosa ya kiungo kwenye miamala. Angalia upya miamala ya hivi karibuni na hakikisha kila muamala una sehemu ya debit na credit sawa.",
                'description_en' => "The trial balance does not match. There are entry errors in transactions. Review recent journal entries and ensure every transaction has equal debit and credit sides.",
                'priority' => 'high',
                'category' => 'compliance',
                'impact' => 'negative',
            ];
        }

        $suggestions[] = [
            'title_sw' => 'Uwasilishaji wa Kodi - TRA',
            'title_en' => 'Tax Filing - TRA',
            'description_sw' => "Hakikisha kodi ya mapato na VAT inawasilishwa kwa TRA kwa wakati. Viwango vya VAT ni 18% na kodi ya mapato ni 30% kwa faida. Tumia faida ya sasa (TSh " . number_format(max(0, $netIncome)) . ") kuhesabu kodi ya mapato.",
            'description_en' => "Ensure income tax and VAT are filed with TRA on time. VAT rate is 18% and corporate income tax is 30% on profits. Use current net income (TSh " . number_format(max(0, $netIncome)) . ") to calculate income tax liability.",
            'priority' => 'high',
            'category' => 'tax',
            'impact' => 'neutral',
        ];

        $suggestions[] = [
            'title_sw' => 'Usimamizi wa Mtiririko wa Pesa',
            'title_en' => 'Cash Flow Management',
            'description_sw' => "Fuatilia mtiririko wa pesa kwa kulinganisha malipo na deni. Hakikisha kuna pesa za kutosha kulipa gharama za mwezi ujao. Angalia deni la wateja (Accounts Receivable) na deni la wauzaji (Accounts Payable).",
            'description_en' => "Monitor cash flow by matching inflows against payables. Ensure sufficient cash reserves for next month's expenses. Review Accounts Receivable aging and Accounts Payable schedules.",
            'priority' => 'medium',
            'category' => 'cash_flow',
            'impact' => 'neutral',
        ];

        $suggestions[] = [
            'title_sw' => 'Hifadhi ya Bidhaa',
            'title_en' => 'Inventory Management',
            'description_sw' => "Hakikisha thamani ya hesabu ya bidhaa (1200) inalingana na thamani halisi ya bidhaa kwenye ghala. Fanya ukaguzi wa bidhaa kila mwezi na kurekebisa tofauti.",
            'description_en' => "Ensure Inventory account (1200) balance matches actual warehouse stock value. Perform monthly physical inventory counts and adjust variances.",
            'priority' => 'medium',
            'category' => 'inventory',
            'impact' => 'neutral',
        ];

        return $suggestions;
    }
}
