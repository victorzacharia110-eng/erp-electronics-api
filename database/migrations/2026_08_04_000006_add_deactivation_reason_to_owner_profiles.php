<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            $table->string('deactivation_reason')->nullable()->after('subscription_plan');
        });
    }

    public function down(): void
    {
        Schema::table('owner_profiles', function (Blueprint $table) {
            $table->dropColumn('deactivation_reason');
        });
    }
};
