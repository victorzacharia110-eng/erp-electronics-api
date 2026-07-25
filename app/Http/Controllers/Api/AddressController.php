<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Address;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AddressController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $addresses = Address::where('user_id', $request->user()->id)
            ->orderBy('is_default', 'desc')
            ->get();

        return response()->json($addresses);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'label' => 'nullable|string|max:50',
            'street' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'required|string|max:100',
            'is_default' => 'boolean',
        ]);

        $validated['user_id'] = $request->user()->id;

        if ($validated['is_default'] ?? false) {
            Address::where('user_id', $validated['user_id'])
                ->update(['is_default' => false]);
        }

        $address = Address::create($validated);

        return response()->json($address, 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $address = Address::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'label' => 'nullable|string|max:50',
            'street' => 'required|string|max:255',
            'city' => 'required|string|max:100',
            'state' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'country' => 'required|string|max:100',
            'is_default' => 'boolean',
        ]);

        if ($validated['is_default'] ?? false) {
            Address::where('user_id', $request->user()->id)
                ->update(['is_default' => false]);
        }

        $address->update($validated);

        return response()->json($address->fresh());
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $address = Address::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        $address->delete();

        return response()->json(['message' => 'Address deleted']);
    }
}
