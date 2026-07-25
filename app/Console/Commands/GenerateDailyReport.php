<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\ReportController;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateDailyReport extends Command
{
    protected $signature = 'report:daily {--date=}';
    protected $description = 'Generate daily sales report';

    public function handle(ReportController $controller): int
    {
        $date = $this->option('date')
            ? Carbon::parse($this->option('date'))
            : Carbon::yesterday();

        $report = $controller->generateForDate($date);

        $this->info("Report generated for {$date->toDateString()}:");
        $this->info("  Orders: {$report->total_orders}");
        $this->info("  Revenue: TSh " . number_format($report->total_revenue));
        $this->info("  Paid: {$report->paid_orders} | Pending: {$report->pending_orders} | Cancelled: {$report->cancelled_orders}");

        return Command::SUCCESS;
    }
}
