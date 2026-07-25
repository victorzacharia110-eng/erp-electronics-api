<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->onDelete('cascade');
            $table->string('report_type'); // monthly, yearly, trial_balance, profit_loss, balance_sheet
            $table->string('period_label'); // e.g. "January 2026", "2025"
            $table->date('period_start');
            $table->date('period_end');
            $table->json('data'); // full report data
            $table->json('summary'); // key metrics
            $table->boolean('is_finalized')->default(false);
            $table->timestamps();

            $table->unique(['owner_id', 'report_type', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_reports');
    }
};
