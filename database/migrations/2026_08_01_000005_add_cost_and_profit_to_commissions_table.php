<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->decimal('cost_amount', 14, 2)->default(0)->after('order_amount');
            $table->decimal('profit_amount', 14, 2)->default(0)->after('cost_amount');
        });
    }

    public function down(): void
    {
        Schema::table('commissions', function (Blueprint $table) {
            $table->dropColumn(['cost_amount', 'profit_amount']);
        });
    }
};
