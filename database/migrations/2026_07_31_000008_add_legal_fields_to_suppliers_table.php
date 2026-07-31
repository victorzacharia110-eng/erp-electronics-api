<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->string('business_type')->nullable(); // sole_proprietorship, partnership, limited_company, other
            $table->string('tin_number')->nullable();
            $table->string('vat_number')->nullable();
            $table->string('business_registration_number')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->dropColumn(['business_type', 'tin_number', 'vat_number', 'business_registration_number']);
        });
    }
};
