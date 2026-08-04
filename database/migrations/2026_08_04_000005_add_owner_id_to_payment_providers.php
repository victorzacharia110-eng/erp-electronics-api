<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_providers', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->index('owner_id');
        });

        // Backfill existing providers to the first business's owner so
        // single-shop installs keep their current storefront providers.
        $firstOwner = DB::table('businesses')->orderBy('id')->value('owner_id');
        if ($firstOwner) {
            DB::table('payment_providers')->whereNull('owner_id')->update(['owner_id' => $firstOwner]);
        }
    }

    public function down(): void
    {
        Schema::table('payment_providers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
