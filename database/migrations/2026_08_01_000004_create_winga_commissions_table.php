<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winga_commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('winga_id')->constrained('wingas')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('order_amount', 14, 2)->comment('Order subtotal the commission is based on');
            $table->decimal('commission_rate', 5, 2)->comment('Rate applied to fund the commission');
            $table->decimal('commission_amount', 14, 2)->comment('Gross commission charged to the customer');
            $table->decimal('withholding_tax', 14, 2)->default(0)->comment('TRA withholding tax (TDS) on the commission');
            $table->decimal('net_amount', 14, 2)->comment('Payable to winga after withholding tax');
            $table->enum('status', ['pending', 'paid', 'reversed'])->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['winga_id', 'status']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winga_commissions');
    }
};
