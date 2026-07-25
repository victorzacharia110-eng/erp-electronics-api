<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('tracking_number')->nullable()->after('notes');
            $table->text('delivery_notes')->nullable()->after('tracking_number');
            $table->timestamp('shipped_at')->nullable()->after('delivery_notes');
            $table->timestamp('delivered_at')->nullable()->after('shipped_at');
            $table->boolean('delivery_required')->default(false)->after('delivered_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['tracking_number', 'delivery_notes', 'shipped_at', 'delivered_at', 'delivery_required']);
        });
    }
};
