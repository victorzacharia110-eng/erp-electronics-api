<?php

use App\Models\OwnerProfile;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        $owners = User::where('role', 'owner')->get();
        $taken = DB::table('businesses')->pluck('slug')->flip();

        foreach ($owners as $owner) {
            if (DB::table('businesses')->where('owner_id', $owner->id)->exists()) {
                continue;
            }

            $profile = OwnerProfile::where('user_id', $owner->id)->first();
            $baseName = $profile?->brand_store_name ?: $owner->name;
            $slug = Str::slug($baseName) ?: 'store-' . $owner->id;

            while ($taken->has($slug)) {
                $slug = Str::slug($baseName) . '-' . $owner->id;
            }

            DB::table('businesses')->insert([
                'owner_id' => $owner->id,
                'name' => $baseName,
                'slug' => $slug,
                'tagline' => $profile?->brand_tagline,
                'logo_path' => $profile?->brand_logo_path,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $taken->put($slug, true);
        }
    }

    public function down(): void
    {
        DB::table('businesses')->truncate();
    }
};
