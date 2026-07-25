<?php

namespace Database\Seeders;

use App\Models\OwnerProfile;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperadminSeeder extends Seeder
{
    public function run(): void
    {
        $superadmin = User::updateOrCreate(
            ['email' => 'superadmin@erp-electronics.com'],
            [
                'name' => 'Super Admin',
                'phone' => '+255700000000',
                'password' => Hash::make('SuperAdmin@2026'),
                'role' => 'superadmin',
                'is_active' => true,
                'is_superadmin' => true,
                'password_changed_at' => now(),
            ]
        );

        // Ensure the existing owner has an owner_profile
        $owner = User::where('role', 'owner')->first();
        if ($owner && !$owner->ownerProfile) {
            OwnerProfile::create([
                'user_id' => $owner->id,
                'is_active' => true,
                'subscription_status' => 'trial',
                'subscription_expires_at' => now()->addDays(30),
                'subscription_plan' => 'starter',
                'max_products' => 50,
                'max_employees' => 5,
                'brand_store_name' => 'ElectroShop',
                'brand_tagline' => 'Your trusted electronics store',
                'brand_color' => '#e74c3c',
                'brand_color_secondary' => '#2c3e50',
            ]);
        }
    }
}
