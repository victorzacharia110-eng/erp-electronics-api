<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('owner_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Access control
            $table->boolean('is_active')->default(true);

            // Subscription
            $table->string('subscription_status')->default('trial'); // trial, active, suspended, expired
            $table->timestamp('subscription_expires_at')->nullable();
            $table->string('subscription_plan')->default('free'); // free, starter, pro, enterprise

            // Limits
            $table->integer('max_products')->default(50);
            $table->integer('max_employees')->default(5);

            // White-label branding
            $table->string('brand_store_name')->nullable();
            $table->string('brand_tagline')->nullable();
            $table->string('brand_logo_path')->nullable();
            $table->string('brand_color')->default('#e74c3c');
            $table->string('brand_color_secondary')->default('#2c3e50');

            $table->timestamps();

            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('owner_profiles');
    }
};
