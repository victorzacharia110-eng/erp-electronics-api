<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountingReportService;
use App\Services\AiSuggestionService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateAccountingReports extends Command
{
    protected $signature = 'accounting:generate-reports {--year=} {--month=} {--with-suggestions}';
    protected $description = 'Generate the monthly accounting report (and AI suggestions) for every owner';

    public function handle(AccountingReportService $reports, AiSuggestionService $suggestions): int
    {
        $date = Carbon::today()->subMonth();
        if ($this->option('year')) {
            $date = Carbon::create((int) $this->option('year'), (int) ($this->option('month') ?: $date->month), 1);
        }
        $date = $date->copy()->startOfMonth();

        $owners = User::where('role', 'owner')->where('is_active', true)->get();

        if ($owners->isEmpty()) {
            $this->info('No active owners found.');
            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($owners as $owner) {
            try {
                $report = $reports->generateMonthlyReport($owner, $date->year, $date->month);
                $this->info("Generated monthly report for {$owner->name}: {$report->period_label}");

                if ($this->option('with-suggestions')) {
                    $result = $suggestions->generate($report);
                    $report->update([
                        'suggestions' => $result['suggestions'],
                        'suggestions_source' => $result['source'],
                        'suggestions_generated_at' => now(),
                    ]);
                    $this->info("  Suggestions generated ({$result['source']}): " . count($result['suggestions']));
                }
            } catch (\Throwable $e) {
                $failures++;
                $this->error("{$owner->name}: " . $e->getMessage());
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
