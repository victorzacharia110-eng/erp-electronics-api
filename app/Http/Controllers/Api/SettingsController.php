<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\OwnerProfile;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    public function payment(): JsonResponse
    {
        $clickpesaEnabled = Setting::where('key', 'clickpesa_enabled')->first();

        return response()->json([
            'clickpesa_enabled' => $clickpesaEnabled ? $clickpesaEnabled->getTypedValue() : false,
        ]);
    }

    public function updatePayment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'clickpesa_enabled' => 'required|boolean',
        ]);

        Setting::updateOrCreate(
            ['key' => 'clickpesa_enabled'],
            [
                'value' => $validated['clickpesa_enabled'] ? 'true' : 'false',
                'type' => 'boolean',
            ]
        );

        return response()->json([
            'message' => 'Payment settings updated',
            'clickpesa_enabled' => $validated['clickpesa_enabled'],
        ]);
    }

    public function branding(Request $request): JsonResponse
    {
        $profile = null;

        if ($business = \App\Support\Tenant::bySlug($request->query('business'))) {
            $profile = OwnerProfile::where('user_id', $business->owner_id)->with('user')->first();
        }

        if (!$profile) {
            $profile = OwnerProfile::where('is_active', true)->with('user')->first();
        }

        if (!$profile) {
            return response()->json([
                'store_name' => 'ElectroShop',
                'tagline' => 'Your trusted electronics store',
                'logo_path' => null,
                'color' => '#e74c3c',
                'color_secondary' => '#2c3e50',
            ]);
        }

        return response()->json([
            'store_name' => $profile->brand_store_name || 'ElectroShop',
            'tagline' => $profile->brand_tagline || 'Your trusted electronics store',
            'logo_path' => $profile->brand_logo_path ? '/branding/' . $profile->brand_logo_path : null,
            'color' => $profile->brand_color || '#e74c3c',
            'color_secondary' => $profile->brand_color_secondary || '#2c3e50',
        ]);
    }
}
