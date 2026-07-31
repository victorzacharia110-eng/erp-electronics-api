<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE commissions MODIFY status VARCHAR(20) NOT NULL DEFAULT 'pending'");
            return;
        }

        // SQLite: recreate table with a wider status column.
        DB::statement('PRAGMA foreign_keys = OFF');
        DB::statement("ALTER TABLE commissions RENAME TO _commissions_reversed_migration");
        Schema::create('commissions', function ($table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('order_amount', 14, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->string('status', 20)->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
        DB::statement('INSERT INTO commissions (id, owner_id, employee_id, order_id, order_amount, commission_rate, commission_amount, status, journal_entry_id, paid_at, created_at, updated_at)
            SELECT id, owner_id, employee_id, order_id, order_amount, commission_rate, commission_amount, status, journal_entry_id, paid_at, created_at, updated_at FROM _commissions_reversed_migration');
        DB::statement('DROP TABLE _commissions_reversed_migration');
        DB::statement('PRAGMA foreign_keys = ON');
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE commissions MODIFY status ENUM('pending', 'paid') NOT NULL DEFAULT 'pending'");
        }
    }
};
