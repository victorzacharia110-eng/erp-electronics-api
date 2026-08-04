<?php

namespace App\Console\Commands;

use App\Models\OwnerProfile;
use Illuminate\Console\Command;

class DeactivateExpiredSubscriptions extends Command
{
    protected $signature = 'subscription:deactivate-expired';

    protected $description = 'Automatically deactivate owners whose trial/subscription has expired';

    public function handle(): int
    {
        $now = now();

        $profiles = OwnerProfile::where('is_active', true)
            ->whereIn('subscription_status', ['trial', 'active'])
            ->whereNotNull('subscription_expires_at')
            ->where('subscription_expires_at', '<=', $now)
            ->with('user')
            ->get();

        $count = 0;

        foreach ($profiles as $profile) {
            $profile->update([
                'is_active' => false,
                'subscription_status' => 'expired',
                'deactivation_reason' => 'trial_expired',
            ]);

            if ($profile->user) {
                $profile->user->update(['is_active' => false]);
            }

            $name = $profile->user ? $profile->user->name : 'User #' . $profile->user_id;
            $this->info("Deactivated {$name} (trial/subscription expired).");
            $count++;
        }

        if ($count === 0) {
            $this->info('No expired subscriptions to deactivate.');
        }

        return self::SUCCESS;
    }
}
