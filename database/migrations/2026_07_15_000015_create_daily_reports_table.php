<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_reports', function (Blueprint $table) {
            $table->id();
            $table->date('report_date')->unique();
            $table->integer('total_orders')->default(0);
            $table->decimal('total_revenue', 14, 2)->default(0);
            $table->integer('total_items_sold')->default(0);
            $table->integer('paid_orders')->default(0);
            $table->integer('pending_orders')->default(0);
            $table->integer('cancelled_orders')->default(0);
            $table->json('employee_stats')->nullable();
            $table->json('top_products')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_reports');
    }
};
