<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;

class CleanupUnpaidOrders extends Command
{
    protected $signature = 'orders:cleanup-unpaid';
    protected $description = 'Mark unpaid pending orders as inactive after 3 hours, then delete inactive orders after 3 more hours';

    public function handle(): int
    {
        $now = now();

        // 1) Mark pending/pending_payment orders older than 3 hours as inactive
        $cutoffInactive = $now->copy()->subHours(3);
        $marked = Order::whereIn('status', ['pending', 'pending_payment'])
            ->where('created_at', '<=', $cutoffInactive)
            ->update(['status' => 'inactive', 'updated_at' => $now]);

        if ($marked > 0) {
            $this->info("Marked {$marked} unpaid order(s) as inactive.");
        }

        // 2) Delete inactive orders older than 6 hours (3h to become inactive + 3h more)
        $cutoffDelete = $now->copy()->subHours(6);
        $deleted = Order::where('status', 'inactive')
            ->where('created_at', '<=', $cutoffDelete)
            ->delete();

        if ($deleted > 0) {
            $this->info("Deleted {$deleted} old inactive order(s).");
        }

        if ($marked === 0 && $deleted === 0) {
            $this->info('No unpaid orders to process.');
        }

        return self::SUCCESS;
    }
}
