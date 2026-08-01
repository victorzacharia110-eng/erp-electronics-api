<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_user', function (Blueprint $table) {
            $table->foreignId('business_id')->constrained('businesses')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('role')->default('owner');
            $table->timestamps();

            $table->primary(['business_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_user');
    }
};
