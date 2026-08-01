<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\OwnerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BusinessController extends Controller
{
    /**
     * Public directory of active businesses (storefront homepage).
     */
    public function index(Request $request): JsonResponse
    {
        $businesses = Business::where('is_active', true)
            ->with('owner.ownerProfile')
            ->withCount(['products' => function ($q) {
                $q->where('is_active', true);
            }])
            ->orderBy('name')
            ->get()
            ->map(fn (Business $b) => $this->present($b));

        return response()->json(['data' => $businesses]);
    }

    /**
     * Resolve a single business by slug (storefront context).
     */
    public function show(string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)
            ->where('is_active', true)
            ->with('owner.ownerProfile')
            ->firstOrFail();

        return response()->json($this->present($business));
    }

    /**
     * Businesses the authenticated owner (or co-owner) can manage.
     */
    public function mine(Request $request): JsonResponse
    {
        $businesses = \App\Support\Tenant::forUser($request->user());

        return response()->json(['data' => $businesses->map(fn (Business $b) => $this->present($b))]);
    }

    /**
     * Resolve a business by slug for an owner, used to set the active context.
     */
    public function bySlugForUser(Request $request, string $slug): JsonResponse
    {
        $business = Business::where('slug', $slug)->firstOrFail();

        $manageable = \App\Support\Tenant::forUser($request->user());

        if (!$manageable->contains('id', $business->id)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        return response()->json($this->present($business));
    }

    private function present(Business $business): array
    {
        $profile = $business->owner?->ownerProfile;

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'tagline' => $business->tagline,
            'logo_path' => $business->logo_path ? '/branding/' . $business->logo_path : null,
            'is_active' => $business->is_active,
            'products_count' => $business->products_count ?? 0,
            'store_name' => $profile?->brand_store_name ?: $business->name,
            'brand_color' => $profile?->brand_color ?: '#e74c3c',
            'brand_color_secondary' => $profile?->brand_color_secondary ?: '#2c3e50',
        ];
    }
}
