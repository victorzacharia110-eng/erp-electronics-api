<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if ($ownerId = $this->publicOwnerId($request)) {
            $query = Category::where('owner_id', $ownerId);
        } else {
            $query = Category::query()->whereRaw('1 = 0');
        }

        $categories = $query->withCount(['products' => function ($q) {
            $q->where('is_active', true);
        }])
            ->with(['children' => function ($query) {
                $query->where('is_active', true);
            }])
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get()
            ->map(function ($cat) {
                $cat->translated_name = $cat->translated_name;

                return $cat;
            });

        return response()->json($categories);
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $ownerId = $this->publicOwnerId($request);

        if ($ownerId === null) {
            return response()->json(['message' => 'Category not found'], 404);
        }

        $category = Category::where('owner_id', $ownerId)
            ->with(['products' => function ($q) use ($ownerId) {
                $q->where('is_active', true)
                    ->where('owner_id', $ownerId)
                    ->with('variants.inventory');
            }])
            ->where('slug', $slug)
            ->firstOrFail();

        $category->translated_name = $category->translated_name;

        return response()->json($category);
    }

    private function publicOwnerId(Request $request): ?int
    {
        if ($business = Tenant::bySlug($request->query('business'))) {
            return $business->owner_id;
        }

        $user = $request->user() ?? $request->user('sanctum');

        if ($user && $user->isOwner()) {
            return $user->ownedBusiness()?->owner_id ?? $user->id;
        }

        if ($user && $user->isEmployee()) {
            return $user->employeeProfile?->branch?->owner_id;
        }

        return null;
    }
}
