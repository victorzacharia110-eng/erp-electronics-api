<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        Setting::firstOrCreate(
            ['key' => 'winga_wht_rate'],
            ['type' => 'decimal', 'value' => '5.00']
        );
    }

    public function down(): void
    {
        Setting::where('key', 'winga_wht_rate')->delete();
    }
};
