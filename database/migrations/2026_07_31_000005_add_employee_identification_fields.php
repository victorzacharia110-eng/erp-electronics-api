<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->string('nida_number', 20)->nullable()->unique();
            $table->string('voting_id_number', 30)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_profiles', function (Blueprint $table) {
            $table->dropUnique(['nida_number']);
            $table->dropColumn(['nida_number', 'voting_id_number']);
        });
    }
};
