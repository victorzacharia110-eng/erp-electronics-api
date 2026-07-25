<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class CustomerController extends Controller
{
    public function index(): JsonResponse
    {
        $customers = User::where('role', 'customer')
            ->withCount('orders')
            ->select('id', 'name', 'email', 'phone', 'is_active', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json($customers);
    }

    public function toggleStatus(User $user): JsonResponse
    {
        $user->update(['is_active' => !$user->is_active]);

        return response()->json([
            'user' => $user->only('id', 'name', 'email', 'is_active'),
            'message' => $user->is_active ? 'Customer activated' : 'Customer deactivated',
        ]);
    }

    public function destroy(User $user): JsonResponse
    {
        if ($user->isOwner()) {
            return response()->json(['message' => 'Cannot delete an owner'], 403);
        }

        $user->delete();

        return response()->json(['message' => 'Customer deleted']);
    }
}
