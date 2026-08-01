<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        if ($business = \App\Support\Tenant::bySlug($request->query('business'))) {
            $query->where('owner_id', $business->owner_id);
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
        $query = Category::query();

        if ($business = \App\Support\Tenant::bySlug($request->query('business'))) {
            $query->where('owner_id', $business->owner_id);
        }

        $category = $query->with(['products' => function ($q) use ($request) {
            $q->where('is_active', true)->with('variants.inventory');

            if ($business = \App\Support\Tenant::bySlug($request->query('business'))) {
                $q->where('owner_id', $business->owner_id);
            }
        }])
            ->where('slug', $slug)
            ->firstOrFail();

        $category->translated_name = $category->translated_name;

        return response()->json($category);
    }
}
