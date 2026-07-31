<?php

namespace App\Console\Commands;

use App\Exceptions\AccountingException;
use App\Models\User;
use App\Services\AccountingEntryService;
use Illuminate\Console\Command;

class CloseYearAccounting extends Command
{
    protected $signature = 'accounting:close-year {--year=}';
    protected $description = 'Post year-end closing entries (zero out revenue/expenses into retained earnings) for every owner';

    public function handle(AccountingEntryService $entries): int
    {
        $year = $this->option('year') ? (int) $this->option('year') : (int) date('Y');

        $owners = User::where('role', 'owner')->where('is_active', true)->get();

        if ($owners->isEmpty()) {
            $this->info('No active owners found.');
            return self::SUCCESS;
        }

        $failures = 0;

        foreach ($owners as $owner) {
            try {
                $entry = $entries->closeYear($owner, $year);
                $this->info("Closed {$year} for {$owner->name}: {$entry->reference}");
            } catch (AccountingException $e) {
                $this->warn("{$owner->name}: {$e->getMessage()}");
            } catch (\Throwable $e) {
                $failures++;
                $this->error("{$owner->name}: " . $e->getMessage());
            }
        }

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }
}
