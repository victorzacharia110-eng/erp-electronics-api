<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->foreignId('employee_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->enum('type', ['superadmin_owner', 'customer_owner', 'owner_employee'])
                ->default('superadmin_owner')
                ->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('employee_id');
            $table->enum('type', ['superadmin_owner', 'customer_owner'])
                ->default('superadmin_owner')
                ->change();
        });
    }
};
