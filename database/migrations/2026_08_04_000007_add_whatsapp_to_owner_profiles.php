<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            $table->string('whatsapp_number')->nullable()->after('brand_color_secondary');
            $table->string('whatsapp_default_message')->nullable()->after('whatsapp_number');
        });
    }

    public function down(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_number', 'whatsapp_default_message']);
        });
    }
};
