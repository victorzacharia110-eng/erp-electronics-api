<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\User;
use Illuminate\Database\Seeder;

class AccountingSeeder extends Seeder
{
    public function run(): void
    {
        $owner = User::where('role', 'owner')->first();
        if (!$owner) return;

        $accounts = [
            // Assets
            ['code' => '1010', 'name' => 'Cash on Hand', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'Physical cash in the business', 'is_system' => true],
            ['code' => '1020', 'name' => 'M-Pesa Account', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'M-Pesa mobile money account', 'is_system' => true],
            ['code' => '1030', 'name' => 'Bank Account', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'Main bank account', 'is_system' => true],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'Money owed by customers', 'is_system' => true],
            ['code' => '1200', 'name' => 'Inventory', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'Products held for sale', 'is_system' => true],
            ['code' => '1500', 'name' => 'Office Equipment', 'type' => 'asset', 'normal_balance' => 'debit', 'description' => 'Computers, furniture, etc.'],

            // Liabilities
            ['code' => '2010', 'name' => 'Accounts Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'Money owed to suppliers'],
            ['code' => '2100', 'name' => 'Customer Deposits', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'Advance payments from customers'],
            ['code' => '2500', 'name' => 'VAT Output', 'type' => 'liability', 'normal_balance' => 'credit', 'description' => 'VAT collected on sales to be remitted to TRA', 'is_system' => true],

            // Equity
            ['code' => '3010', 'name' => "Owner's Capital", 'type' => 'equity', 'normal_balance' => 'credit', 'description' => 'Owner investment in the business', 'is_system' => true],
            ['code' => '3020', 'name' => 'Retained Earnings', 'type' => 'equity', 'normal_balance' => 'credit', 'description' => 'Accumulated profits', 'is_system' => true],

            // Revenue
            ['code' => '4010', 'name' => 'Sales Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'Income from product sales', 'is_system' => true],
            ['code' => '4020', 'name' => 'Shipping Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'Income from delivery fees', 'is_system' => true],
            ['code' => '4030', 'name' => 'Other Income', 'type' => 'revenue', 'normal_balance' => 'credit', 'description' => 'Miscellaneous income'],

            // Expenses
            ['code' => '5010', 'name' => 'Cost of Goods Sold', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Direct cost of products sold', 'is_system' => true],
            ['code' => '5020', 'name' => 'Delivery Expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Shipping and delivery costs'],
            ['code' => '5030', 'name' => 'Rent', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Office/shop rent'],
            ['code' => '5040', 'name' => 'Utilities', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Electricity, water, internet'],
            ['code' => '5050', 'name' => 'Salaries', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Employee salaries'],
            ['code' => '5060', 'name' => 'Office Supplies', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Stationery, printer ink, etc.'],
            ['code' => '5070', 'name' => 'Marketing', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Advertising and promotions'],
            ['code' => '5080', 'name' => 'Bank Charges', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Transaction fees and bank charges'],
            ['code' => '5090', 'name' => 'Miscellaneous Expenses', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Other business expenses'],
            ['code' => '5100', 'name' => 'Inventory Adjustments', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Stock write-offs, damage, and adjustment variances', 'is_system' => true],
            ['code' => '5110', 'name' => 'Commission Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'description' => 'Employee commission payouts', 'is_system' => true],
        ];

        foreach ($accounts as $account) {
            Account::create(array_merge($account, [
                'owner_id' => $owner->id,
            ]));
        }

        $this->seedJournalEntries($owner);
    }

    private function seedJournalEntries(User $owner): void
    {
        $getAccount = fn(string $code) => Account::where('owner_id', $owner->id)->where('code', $code)->first();

        $entries = [
            // May 2026 - Initial capital investment
            [
                'date' => '2026-05-01',
                'description' => 'Initial owner capital investment',
                'lines' => [
                    ['code' => '1020', 'debit' => 5000000, 'credit' => 0, 'description' => 'M-Pesa deposit'],
                    ['code' => '3010', 'debit' => 0, 'credit' => 5000000, 'description' => 'Owner capital contribution'],
                ],
            ],
            // May 2026 - Buy inventory
            [
                'date' => '2026-05-03',
                'description' => 'Purchase of Samsung and Tecno phones',
                'lines' => [
                    ['code' => '1200', 'debit' => 8500000, 'credit' => 0, 'description' => 'Inventory received'],
                    ['code' => '2010', 'debit' => 0, 'credit' => 8500000, 'description' => 'Owed to supplier'],
                ],
            ],
            // May 2026 - Pay supplier
            [
                'date' => '2026-05-10',
                'description' => 'Partial payment to supplier',
                'lines' => [
                    ['code' => '2010', 'debit' => 5000000, 'credit' => 0, 'description' => 'Reducing payable'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 5000000, 'description' => 'M-Pesa payment'],
                ],
            ],
            // May 2026 - Sales for the month
            [
                'date' => '2026-05-15',
                'description' => 'Sales revenue - Samsung Galaxy A15 and accessories',
                'lines' => [
                    ['code' => '1020', 'debit' => 2700000, 'credit' => 0, 'description' => 'M-Pesa received'],
                    ['code' => '4010', 'debit' => 0, 'credit' => 2700000, 'description' => 'Sales revenue'],
                ],
            ],
            [
                'date' => '2026-05-15',
                'description' => 'Cost of goods sold - May sales',
                'lines' => [
                    ['code' => '5010', 'debit' => 2100000, 'credit' => 0, 'description' => 'COGS for May sales'],
                    ['code' => '1200', 'debit' => 0, 'credit' => 2100000, 'description' => 'Inventory reduced'],
                ],
            ],
            // May 2026 - Rent
            [
                'date' => '2026-05-01',
                'description' => 'Monthly shop rent',
                'lines' => [
                    ['code' => '5030', 'debit' => 500000, 'credit' => 0, 'description' => 'May rent'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 500000, 'description' => 'Rent paid via M-Pesa'],
                ],
            ],
            // May 2026 - Utilities
            [
                'date' => '2026-05-20',
                'description' => 'Electricity and internet bills',
                'lines' => [
                    ['code' => '5040', 'debit' => 150000, 'credit' => 0, 'description' => 'Utilities'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 150000, 'description' => 'Paid via M-Pesa'],
                ],
            ],
            // May 2026 - Salaries
            [
                'date' => '2026-05-28',
                'description' => 'Employee salary - Mathew Zacharia',
                'lines' => [
                    ['code' => '5050', 'debit' => 400000, 'credit' => 0, 'description' => 'Salary expense'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 400000, 'description' => 'Salary paid via M-Pesa'],
                ],
            ],
            // May 2026 - Office supplies
            [
                'date' => '2026-05-22',
                'description' => 'Office supplies purchase',
                'lines' => [
                    ['code' => '5060', 'debit' => 50000, 'credit' => 0, 'description' => 'Stationery and supplies'],
                    ['code' => '1010', 'debit' => 0, 'credit' => 50000, 'description' => 'Paid cash'],
                ],
            ],

            // June 2026
            [
                'date' => '2026-06-01',
                'description' => 'Monthly shop rent - June',
                'lines' => [
                    ['code' => '5030', 'debit' => 500000, 'credit' => 0, 'description' => 'June rent'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 500000, 'description' => 'Rent paid via M-Pesa'],
                ],
            ],
            // June - More inventory
            [
                'date' => '2026-06-05',
                'description' => 'Restock iPhone and Xiaomi phones',
                'lines' => [
                    ['code' => '1200', 'debit' => 12000000, 'credit' => 0, 'description' => 'New inventory'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 6000000, 'description' => 'Partial M-Pesa payment'],
                    ['code' => '2010', 'debit' => 0, 'credit' => 6000000, 'description' => 'Balance on credit'],
                ],
            ],
            // June - Sales
            [
                'date' => '2026-06-15',
                'description' => 'Sales revenue - iPhone 15 and Xiaomi',
                'lines' => [
                    ['code' => '1020', 'debit' => 4200000, 'credit' => 0, 'description' => 'M-Pesa received'],
                    ['code' => '1010', 'debit' => 800000, 'credit' => 0, 'description' => 'Cash received'],
                    ['code' => '4010', 'debit' => 0, 'credit' => 5000000, 'description' => 'Sales revenue'],
                ],
            ],
            [
                'date' => '2026-06-15',
                'description' => 'Cost of goods sold - June sales',
                'lines' => [
                    ['code' => '5010', 'debit' => 3500000, 'credit' => 0, 'description' => 'COGS for June sales'],
                    ['code' => '1200', 'debit' => 0, 'credit' => 3500000, 'description' => 'Inventory reduced'],
                ],
            ],
            // June - Shipping revenue
            [
                'date' => '2026-06-20',
                'description' => 'Delivery fees collected',
                'lines' => [
                    ['code' => '1020', 'debit' => 120000, 'credit' => 0, 'description' => 'Shipping collected'],
                    ['code' => '4020', 'debit' => 0, 'credit' => 120000, 'description' => 'Shipping revenue'],
                ],
            ],
            // June - Utilities
            [
                'date' => '2026-06-20',
                'description' => 'Electricity and internet - June',
                'lines' => [
                    ['code' => '5040', 'debit' => 180000, 'credit' => 0, 'description' => 'Utilities June'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 180000, 'description' => 'Paid via M-Pesa'],
                ],
            ],
            // June - Salaries
            [
                'date' => '2026-06-28',
                'description' => 'Employee salary - June',
                'lines' => [
                    ['code' => '5050', 'debit' => 400000, 'credit' => 0, 'description' => 'Salary expense June'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 400000, 'description' => 'Salary paid'],
                ],
            ],
            // June - Marketing
            [
                'date' => '2026-06-10',
                'description' => 'Social media marketing campaign',
                'lines' => [
                    ['code' => '5070', 'debit' => 100000, 'credit' => 0, 'description' => 'Instagram & WhatsApp ads'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 100000, 'description' => 'Paid via M-Pesa'],
                ],
            ],
            // June - Bank charges
            [
                'date' => '2026-06-30',
                'description' => 'M-Pesa transaction fees',
                'lines' => [
                    ['code' => '5080', 'debit' => 35000, 'credit' => 0, 'description' => 'Transaction fees'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 35000, 'description' => 'Fees deducted'],
                ],
            ],

            // July 2026
            [
                'date' => '2026-07-01',
                'description' => 'Monthly shop rent - July',
                'lines' => [
                    ['code' => '5030', 'debit' => 500000, 'credit' => 0, 'description' => 'July rent'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 500000, 'description' => 'Rent paid'],
                ],
            ],
            // July - Sales
            [
                'date' => '2026-07-10',
                'description' => 'Sales - Accessories and earbuds',
                'lines' => [
                    ['code' => '1020', 'debit' => 1800000, 'credit' => 0, 'description' => 'M-Pesa received'],
                    ['code' => '1010', 'debit' => 350000, 'credit' => 0, 'description' => 'Cash received'],
                    ['code' => '4010', 'debit' => 0, 'credit' => 2150000, 'description' => 'Sales revenue'],
                ],
            ],
            [
                'date' => '2026-07-10',
                'description' => 'COGS - July accessories sales',
                'lines' => [
                    ['code' => '5010', 'debit' => 1100000, 'credit' => 0, 'description' => 'COGS July'],
                    ['code' => '1200', 'debit' => 0, 'credit' => 1100000, 'description' => 'Inventory reduced'],
                ],
            ],
            // July - Pay supplier
            [
                'date' => '2026-07-08',
                'description' => 'Payment to supplier - remaining balance',
                'lines' => [
                    ['code' => '2010', 'debit' => 6000000, 'credit' => 0, 'description' => 'Settling payable'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 6000000, 'description' => 'M-Pesa payment'],
                ],
            ],
            // July - Utilities
            [
                'date' => '2026-07-15',
                'description' => 'Utilities - July',
                'lines' => [
                    ['code' => '5040', 'debit' => 170000, 'credit' => 0, 'description' => 'Electricity and internet'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 170000, 'description' => 'Paid'],
                ],
            ],
            // July - Salaries
            [
                'date' => '2026-07-28',
                'description' => 'Employee salary - July',
                'lines' => [
                    ['code' => '5050', 'debit' => 400000, 'credit' => 0, 'description' => 'Salary July'],
                    ['code' => '1020', 'debit' => 0, 'credit' => 400000, 'description' => 'Paid'],
                ],
            ],
        ];

        foreach ($entries as $entryData) {
            $lines = $entryData['lines'];
            unset($entryData['lines']);

            $ref = JournalEntry::generateReference($owner->id);

            $entry = JournalEntry::create(array_merge($entryData, [
                'owner_id' => $owner->id,
                'reference' => $ref,
                'status' => 'posted',
                'prepared_by' => $owner->id,
                'posted_by' => $owner->id,
                'posted_at' => $entryData['date'] . ' 12:00:00',
            ]));

            foreach ($lines as $line) {
                $account = $getAccount($line['code']);
                if ($account) {
                    JournalLine::create([
                        'journal_entry_id' => $entry->id,
                        'account_id' => $account->id,
                        'debit' => $line['debit'],
                        'credit' => $line['credit'],
                        'description' => $line['description'],
                    ]);
                }
            }
        }
    }
}
