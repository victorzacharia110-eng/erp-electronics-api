<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $systemAccounts = [
            ['code' => '2100', 'name' => 'Winga Commission Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'Commissions owed to wingas (street promoters) for customers brought to the shop', 'is_system' => true],
            ['code' => '2120', 'name' => 'Withholding Tax Payable (TDS)', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'TRA withholding tax (TDS) withheld on commission payments, pending remittance', 'is_system' => true],
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
        Account::whereIn('code', ['2100', '2120'])->delete();
    }
};
