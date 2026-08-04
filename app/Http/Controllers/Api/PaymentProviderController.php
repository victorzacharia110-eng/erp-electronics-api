<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Business;
use App\Models\PaymentProvider;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentProviderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $providers = PaymentProvider::where('owner_id', $request->user()->id)
            ->orderBy('sort_order')
            ->get();

        return response()->json($providers);
    }

    public function publicIndex(Request $request): JsonResponse
    {
        $ownerId = null;

        if ($slug = $request->query('business')) {
            $business = Tenant::bySlug($slug);
            $ownerId = $business?->owner_id;
        }

        if (!$ownerId) {
            // Fallback for directory/billing contexts: use the first active
            // business's owner so existing single-shop installs keep working.
            $ownerId = Business::where('is_active', true)->orderBy('id')->value('owner_id');
        }

        $providers = PaymentProvider::where('owner_id', $ownerId)
            ->where('enabled', true)
            ->orderBy('sort_order')
            ->get();

        return response()->json($providers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'slug' => 'required|string|max:50|unique:payment_providers,slug',
            'number' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
            'enabled' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $validated['enabled'] = $validated['enabled'] ?? true;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['owner_id'] = $request->user()->id;

        $provider = PaymentProvider::create($validated);

        return response()->json($provider, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $provider = PaymentProvider::where('owner_id', $request->user()->id)->findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100',
            'number' => 'nullable|string|max:20',
            'icon' => 'nullable|string|max:50',
            'enabled' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $provider->update($validated);

        return response()->json($provider->fresh());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $provider = PaymentProvider::where('owner_id', $request->user()->id)->findOrFail($id);
        $provider->delete();

        return response()->json(['message' => 'Provider deleted']);
    }
}
