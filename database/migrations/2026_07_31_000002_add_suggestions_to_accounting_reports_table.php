<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounting_reports', function (Blueprint $table) {
            $table->json('suggestions')->nullable()->after('summary');
            $table->string('suggestions_source')->nullable()->after('suggestions');
            $table->timestamp('suggestions_generated_at')->nullable()->after('suggestions_source');
        });
    }

    public function down(): void
    {
        Schema::table('accounting_reports', function (Blueprint $table) {
            $table->dropColumn(['suggestions', 'suggestions_source', 'suggestions_generated_at']);
        });
    }
};
