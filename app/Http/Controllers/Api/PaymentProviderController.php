<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PaymentProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentProviderController extends Controller
{
    public function index(): JsonResponse
    {
        $providers = PaymentProvider::orderBy('sort_order')->get();

        return response()->json($providers);
    }

    public function publicIndex(): JsonResponse
    {
        $providers = PaymentProvider::where('enabled', true)
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

        $provider = PaymentProvider::create($validated);

        return response()->json($provider, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $provider = PaymentProvider::findOrFail($id);

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

    public function destroy(string $id): JsonResponse
    {
        $provider = PaymentProvider::findOrFail($id);
        $provider->delete();

        return response()->json(['message' => 'Provider deleted']);
    }
}
