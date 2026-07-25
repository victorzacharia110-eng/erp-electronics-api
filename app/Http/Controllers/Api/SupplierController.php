<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Supplier::where('owner_id', $user->id)->withCount('purchaseOrders');

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $suppliers = $query->orderBy('name')->paginate(20);
        return response()->json($suppliers);
    }

    public function all(Request $request): JsonResponse
    {
        $suppliers = Supplier::where('owner_id', $request->user()->id)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return response()->json($suppliers);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'products_description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
        ]);

        $supplier = Supplier::create([
            ...$validated,
            'owner_id' => $request->user()->id,
        ]);

        return response()->json($supplier, 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $supplier = Supplier::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->withCount('purchaseOrders')
            ->with('purchaseOrders')
            ->firstOrFail();

        return response()->json($supplier);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $supplier = Supplier::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'contact_person' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'city' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'products_description' => 'nullable|string|max:1000',
            'notes' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        $supplier->update($validated);
        return response()->json($supplier);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $supplier = Supplier::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        if ($supplier->purchaseOrders()->count() > 0) {
            return response()->json(['message' => 'Supplier has purchase orders and cannot be deleted'], 422);
        }

        $supplier->delete();
        return response()->json(['message' => 'Supplier deleted']);
    }

    public function supplierProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        $supplier = Supplier::where('owner_id', $user->owner_id ?? $user->id)
            ->where('email', $user->email)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier profile not found'], 404);
        }

        return response()->json($supplier);
    }
}
