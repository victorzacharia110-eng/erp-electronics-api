<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\OwnerProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
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
            ->withCount([
                'products' => fn($q) => $q->where('is_active', true),
                'newProducts' => fn($q) => $q->where('is_active', true)->where('created_at', '>=', Carbon::now()->subDays(7)),
            ])
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
            ->withCount([
                'products' => fn($q) => $q->where('is_active', true),
                'newProducts' => fn($q) => $q->where('is_active', true)->where('created_at', '>=', Carbon::now()->subDays(7)),
            ])
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

    /**
     * Update store contact, WhatsApp and social links for an owned business.
     */
    public function update(Request $request, Business $business): JsonResponse
    {
        $manageable = \App\Support\Tenant::forUser($request->user());

        if (!$manageable->contains('id', $business->id)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'whatsapp_number' => 'nullable|string|max:30',
            'whatsapp_default_message' => 'nullable|string|max:500',
            'contact_phone' => 'nullable|string|max:30',
            'contact_email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:255',
            'facebook_url' => 'nullable|url|max:255',
            'instagram_url' => 'nullable|url|max:255',
            'twitter_url' => 'nullable|url|max:255',
            'tiktok_url' => 'nullable|url|max:255',
            'youtube_url' => 'nullable|url|max:255',
        ]);

        $business->update([
            'whatsapp_number' => $validated['whatsapp_number'] ?? null,
            'whatsapp_default_message' => $validated['whatsapp_default_message'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'contact_email' => $validated['contact_email'] ?? null,
            'address' => $validated['address'] ?? null,
            'facebook_url' => $validated['facebook_url'] ?? null,
            'instagram_url' => $validated['instagram_url'] ?? null,
            'twitter_url' => $validated['twitter_url'] ?? null,
            'tiktok_url' => $validated['tiktok_url'] ?? null,
            'youtube_url' => $validated['youtube_url'] ?? null,
        ]);

        return response()->json($this->present($business));
    }

    private function present(Business $business): array
    {
        $profile = $business->owner?->ownerProfile;
        $storeName = $profile?->brand_store_name ?: $business->name;

        return [
            'id' => $business->id,
            'name' => $business->name,
            'slug' => $business->slug,
            'tagline' => $business->tagline,
            'logo_path' => $business->logo_path ? '/branding/' . $business->logo_path : null,
            'is_active' => $business->is_active,
            'products_count' => $business->products_count ?? 0,
            'new_arrivals_count' => $business->new_products_count ?? 0,
            'store_name' => $storeName,
            'brand_color' => $profile?->brand_color ?: '#e74c3c',
            'brand_color_secondary' => $profile?->brand_color_secondary ?: '#2c3e50',
            'whatsapp_number' => $business->whatsapp_number,
            'whatsapp_default_message' => $business->whatsapp_default_message,
            'whatsapp_message' => $business->whatsapp_default_message
                ?: "Hello {$storeName}! I would like to know more about your products.",
            'contact_phone' => $business->contact_phone,
            'contact_email' => $business->contact_email,
            'address' => $business->address,
            'social' => [
                'facebook' => $business->facebook_url,
                'instagram' => $business->instagram_url,
                'twitter' => $business->twitter_url,
                'tiktok' => $business->tiktok_url,
                'youtube' => $business->youtube_url,
            ],
        ];
    }
}
