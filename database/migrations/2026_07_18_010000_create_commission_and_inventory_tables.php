<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->decimal('commission_rate', 5, 2)->default(0)->after('department');
            $table->decimal('base_salary', 14, 2)->default(0)->after('commission_rate');
        });

        Schema::create('commissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('order_amount', 14, 2);
            $table->decimal('commission_rate', 5, 2);
            $table->decimal('commission_amount', 14, 2);
            $table->enum('status', ['pending', 'paid'])->default('pending');
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['sale', 'return', 'adjustment', 'purchase', 'damage', 'opening']);
            $table->integer('quantity_change');
            $table->integer('quantity_after');
            $table->decimal('unit_cost', 14, 2)->nullable();
            $table->string('reference_type')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('po_number', 50);
            $table->string('supplier_name');
            $table->string('supplier_contact')->nullable();
            $table->enum('status', ['draft', 'ordered', 'received', 'cancelled'])->default('draft');
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->date('order_date')->nullable();
            $table->date('expected_date')->nullable();
            $table->date('received_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity');
            $table->decimal('unit_cost', 14, 2);
            $table->decimal('total', 14, 2);
            $table->integer('quantity_received')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
        Schema::dropIfExists('inventory_transactions');
        Schema::dropIfExists('commissions');
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropColumn(['commission_rate', 'base_salary']);
        });
    }
};
