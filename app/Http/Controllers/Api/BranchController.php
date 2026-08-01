<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $branches = Branch::where('owner_id', $request->ownerId())
            ->withCount(['orders', 'employees'])
            ->orderBy('is_default', 'desc')
            ->orderBy('name')
            ->get();

        return response()->json($branches);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
        ], [
            'name.required' => 'Please enter the branch name.',
            'name.max' => 'Branch name is too long.',
        ]);

        $hasNoBranches = Branch::where('owner_id', $request->ownerId())->count() === 0;

        $branch = Branch::create([
            'owner_id' => $request->ownerId(),
            'name' => $validated['name'],
            'city' => $validated['city'] ?? null,
            'address' => $validated['address'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'is_active' => true,
            'is_default' => $hasNoBranches,
        ]);

        return response()->json([
            'branch' => $branch,
            'message' => 'Branch created successfully.',
        ], 201);
    }

    public function show(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->owner_id !== $request->ownerId()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $branch->loadCount(['orders', 'employees']);

        return response()->json($branch);
    }

    public function update(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->owner_id !== $request->ownerId()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'city' => 'nullable|string|max:255',
            'address' => 'nullable|string|max:500',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        $branch->update($validated);

        return response()->json([
            'branch' => $branch,
            'message' => 'Branch updated.',
        ]);
    }

    public function setDefault(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->owner_id !== $request->ownerId()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        Branch::where('owner_id', $branch->owner_id)
            ->where('is_default', true)
            ->update(['is_default' => false]);

        $branch->update(['is_default' => true]);

        return response()->json(['message' => 'Default branch set.']);
    }

    public function destroy(Request $request, Branch $branch): JsonResponse
    {
        if ($branch->owner_id !== $request->ownerId()) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        if ($branch->is_default) {
            return response()->json(['message' => 'Cannot delete the default branch. Set another as default first.'], 400);
        }

        $branch->delete();

        return response()->json(['message' => 'Branch deleted.']);
    }
}
