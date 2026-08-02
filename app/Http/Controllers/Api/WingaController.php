<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Winga;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WingaController extends Controller
{
    private function tenantOwnerId(Request $request): ?int
    {
        if ($ownerId = $request->ownerId()) {
            return $ownerId;
        }

        return $request->user()?->employeeProfile?->branch?->owner_id;
    }

    public function index(Request $request): JsonResponse
    {
        $ownerId = $this->tenantOwnerId($request);

        $query = Winga::forOwner($ownerId)
            ->withCount(['commissions as pending_commissions' => fn ($q) => $q->where('status', 'pending')])
            ->with(['branch']);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('tin_number', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($branchId = $request->query('branch_id')) {
            $query->where('branch_id', $branchId);
        }

        $wingas = $query->orderBy('name')->paginate(20);

        return response()->json($wingas);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validate($request);

        $winga = Winga::create(array_merge($validated, [
            'owner_id' => $this->tenantOwnerId($request),
        ]));

        return response()->json(['winga' => $winga->fresh('branch')], 201);
    }

    public function update(Request $request, Winga $winga): JsonResponse
    {
        $ownerId = $this->tenantOwnerId($request);
        if ($winga->owner_id !== $ownerId) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $validated = $this->validate($request);

        $winga->update($validated);

        return response()->json(['winga' => $winga->fresh('branch')]);
    }

    public function toggleStatus(Request $request, Winga $winga): JsonResponse
    {
        if ($winga->owner_id !== $this->tenantOwnerId($request)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $winga->update([
            'status' => $winga->status === 'active' ? 'inactive' : 'active',
        ]);

        return response()->json(['winga' => $winga]);
    }

    public function destroy(Request $request, Winga $winga): JsonResponse
    {
        if ($winga->owner_id !== $this->tenantOwnerId($request)) {
            return response()->json(['message' => 'Unauthorized.'], 403);
        }

        $winga->delete();

        return response()->json(['message' => 'Winga deleted successfully.']);
    }

    private function validate(Request $request): array
    {
        return $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'nullable|string|max:20',
            'tin_number' => 'nullable|string|max:20',
            'nida_number' => 'nullable|string|max:20',
            'commission_rate' => 'required|numeric|min:0|max:100',
            'branch_id' => [
                'nullable',
                Rule::exists('branches', 'id')->where('owner_id', $this->tenantOwnerId($request)),
            ],
            'status' => 'nullable|in:active,inactive',
        ]);
    }
}
