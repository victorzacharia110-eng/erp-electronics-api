<?php

use App\Models\Account;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $settings = [
            'vat_rate' => ['type' => 'decimal', 'value' => '18.00'],
            'income_tax_rate' => ['type' => 'decimal', 'value' => '30.00'],
            'prices_include_vat' => ['type' => 'boolean', 'value' => 'true'],
            'winga_wht_rate' => ['type' => 'decimal', 'value' => '5.00'],
        ];

        foreach ($settings as $key => $setting) {
            Setting::firstOrCreate(
                ['key' => $key],
                ['value' => $setting['value'], 'type' => $setting['type']]
            );
        }

        $systemAccounts = [
            ['code' => '2500', 'name' => 'VAT Output', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'VAT collected on sales to be remitted to TRA', 'is_system' => true],
            ['code' => '5100', 'name' => 'Inventory Adjustments', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Stock write-offs, damage, and adjustment variances', 'is_system' => true],
            ['code' => '5110', 'name' => 'Commission Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Employee commission payouts', 'is_system' => true],
        ];

        $ownerIds = User::where('role', 'owner')->pluck('id');

        foreach ($ownerIds as $ownerId) {
            foreach ($systemAccounts as $account) {
                Account::firstOrCreate(
                    ['owner_id' => $ownerId, 'code' => $account['code']],
                    $account
                );
            }
        }
    }

    public function down(): void
    {
        Setting::whereIn('key', ['vat_rate', 'income_tax_rate', 'prices_include_vat'])->delete();

        Account::whereIn('code', ['2500', '5100', '5110'])->delete();
    }
};
