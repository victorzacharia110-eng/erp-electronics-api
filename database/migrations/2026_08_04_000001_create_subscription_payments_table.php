<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('plan', ['starter', 'pro', 'enterprise'])->default('starter');
            $table->unsignedTinyInteger('months')->default(1);
            $table->decimal('amount', 12, 2);
            $table->string('provider'); // mpesa, airtel, clickpesa, cash, ...
            $table->string('phone_number')->nullable();
            $table->string('provider_reference')->nullable();
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['provider_reference']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_payments');
    }
};
