<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = Category::withCount(['products' => function ($query) {
            $query->where('is_active', true);
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

    public function show(string $slug): JsonResponse
    {
        $category = Category::with(['products' => function ($query) {
            $query->where('is_active', true)->with('variants.inventory');
        }])
            ->where('slug', $slug)
            ->firstOrFail();

        $category->translated_name = $category->translated_name;

        return response()->json($category);
    }
}
