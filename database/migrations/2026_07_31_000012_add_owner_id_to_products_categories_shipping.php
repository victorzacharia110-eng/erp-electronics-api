<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $firstOwnerId = User::where('role', 'owner')->orderBy('id')->value('id');

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->index('owner_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->index('owner_id');
        });

        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
            $table->index('owner_id');
        });

        if ($firstOwnerId) {
            DB::table('products')->whereNull('owner_id')->update(['owner_id' => $firstOwnerId]);
            DB::table('categories')->whereNull('owner_id')->update(['owner_id' => $firstOwnerId]);
            DB::table('shipping_rules')->whereNull('owner_id')->update(['owner_id' => $firstOwnerId]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['owner_id']);
            $table->dropColumn('owner_id');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['owner_id']);
            $table->dropColumn('owner_id');
        });

        Schema::table('shipping_rules', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
            $table->dropIndex(['owner_id']);
            $table->dropColumn('owner_id');
        });
    }
};
