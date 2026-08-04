<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('report:daily')->dailyAt('00:10');
Schedule::command('orders:cleanup-unpaid')->everyFiveMinutes();
Schedule::command('subscription:deactivate-expired')->hourly();
Schedule::command('superadmin:reset-password')->monthly();
Schedule::command('accounting:generate-reports --with-suggestions')->monthlyOn(1, '00:30');
Schedule::command('accounting:close-year')->yearlyOn(1, 1, '01:00');
