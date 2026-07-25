<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class ResetSuperadminPassword extends Command
{
    protected $signature = 'superadmin:reset-password {--email=}';
    protected $description = 'Reset superadmin password to default if older than 6 months';

    public function handle(): int
    {
        $email = $this->option('email') ?? 'superadmin@erp-electronics.com';
        $defaultPassword = 'SuperAdmin@2026';

        $superadmin = User::where('email', $email)
            ->where('role', 'superadmin')
            ->first();

        if (!$superadmin) {
            $this->error("Superadmin not found: {$email}");
            return Command::FAILURE;
        }

        if (!$superadmin->superadminPasswordExpired()) {
            $this->info("Password is not older than 6 months. No reset needed.");
            return Command::SUCCESS;
        }

        $superadmin->update([
            'password' => Hash::make($defaultPassword),
            'password_changed_at' => now(),
        ]);

        $this->info("Superadmin password reset to default.");
        $this->info("Email: {$email}");
        $this->info("Default password: {$defaultPassword}");

        return Command::SUCCESS;
    }
}
