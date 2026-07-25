<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Inventory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'variants.inventory']);

        if (!$request->boolean('all')) {
            $query->where('is_active', true);
        }

        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                  ->orWhere('brand', 'like', "%{$request->search}%")
                  ->orWhere('sku', 'like', "%{$request->search}%");
            });
        }

        if ($request->has('brand')) {
            $query->where('brand', $request->brand);
        }

        $products = $query->orderBy($request->get('sort', 'created_at'), $request->get('order', 'desc'))
            ->paginate($request->get('per_page', 12));

        return response()->json($products);
    }

    public function show(string $identifier): JsonResponse
    {
        $product = Product::with(['category', 'variants.inventory'])
            ->where('slug', $identifier)
            ->orWhere('id', $identifier)
            ->orWhere('sku', $identifier)
            ->firstOrFail();

        return response()->json($product);
    }

    public function featured(): JsonResponse
    {
        $products = Product::with(['category', 'variants.inventory'])
            ->where('is_active', true)
            ->inRandomOrder()
            ->limit(8)
            ->get();

        return response()->json($products);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100|unique:products',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'brand' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'image_url' => 'nullable|string|max:2000',
            'image_file' => 'nullable|image|max:5120',
            'variants' => 'nullable|array',
            'variants.*.sku' => 'required|string|max:100|unique:product_variants,sku',
            'variants.*.color' => 'nullable|string|max:100',
            'variants.*.storage' => 'nullable|string|max:100',
            'variants.*.price' => 'required|numeric|min:0',
            'variants.*.cost_price' => 'nullable|numeric|min:0',
            'variants.*.quantity' => 'required|integer|min:0',
        ], [
            'name.required' => 'Please enter the product name.',
            'sku.required' => 'Please enter a product SKU.',
            'sku.unique' => 'This SKU already exists. Please use a unique one.',
            'price.required' => 'Please enter the product price.',
            'category_id.required' => 'Please select a category.',
            'category_id.exists' => 'Selected category does not exist.',
            'image_file.max' => 'Image must be less than 5MB.',
            'image_file.image' => 'Please upload a valid image file.',
            'variants.*.sku.required' => 'Each variant needs a unique SKU.',
            'variants.*.sku.unique' => 'This variant SKU already exists.',
            'variants.*.price.required' => 'Each variant needs a price.',
            'variants.*.quantity.required' => 'Each variant needs a stock quantity.',
        ]);

        $imagePath = $this->handleImage($request);

        $product = Product::create([
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'cost_price' => $validated['cost_price'] ?? null,
            'category_id' => $validated['category_id'],
            'brand' => $validated['brand'] ?? null,
            'image' => $imagePath,
            'is_active' => $validated['is_active'] ?? true,
        ]);

        if (!empty($validated['variants'])) {
            foreach ($validated['variants'] as $v) {
                $variant = $product->variants()->create([
                    'sku' => $v['sku'],
                    'color' => $v['color'] ?? null,
                    'storage' => $v['storage'] ?? null,
                    'price' => $v['price'],
                    'cost_price' => $v['cost_price'] ?? null,
                    'is_active' => true,
                ]);

                $variant->inventory()->create([
                    'quantity_on_hand' => $v['quantity'],
                    'reorder_level' => 5,
                ]);
            }
        }

        return response()->json([
            'product' => $product->fresh(['category', 'variants.inventory']),
            'message' => 'Product created successfully',
        ], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'sku' => 'required|string|max:100|unique:products,sku,' . $product->id,
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'cost_price' => 'nullable|numeric|min:0',
            'category_id' => 'required|exists:categories,id',
            'brand' => 'nullable|string|max:255',
            'is_active' => 'boolean',
            'image_url' => 'nullable|string|max:2000',
            'image_file' => 'nullable|image|max:5120',
            'remove_image' => 'boolean',
        ], [
            'name.required' => 'Please enter the product name.',
            'sku.required' => 'Please enter a product SKU.',
            'sku.unique' => 'This SKU already exists. Please use a unique one.',
            'price.required' => 'Please enter the product price.',
            'category_id.required' => 'Please select a category.',
            'category_id.exists' => 'Selected category does not exist.',
            'image_file.max' => 'Image must be less than 5MB.',
            'image_file.image' => 'Please upload a valid image file.',
        ]);

        $imagePath = $product->image;

        if ($request->boolean('remove_image')) {
            $imagePath = null;
        } elseif ($request->hasFile('image_file')) {
            $imagePath = $this->handleImage($request);
        } elseif (!empty($validated['image_url'])) {
            $imagePath = $validated['image_url'];
        }

        $product->update([
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'description' => $validated['description'] ?? null,
            'price' => $validated['price'],
            'cost_price' => $validated['cost_price'] ?? null,
            'category_id' => $validated['category_id'],
            'brand' => $validated['brand'] ?? null,
            'image' => $imagePath,
            'is_active' => $validated['is_active'] ?? $product->is_active,
        ]);

        return response()->json([
            'product' => $product->fresh(['category', 'variants.inventory']),
            'message' => 'Product updated successfully',
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $product = Product::findOrFail($id);
        $product->delete();

        return response()->json(['message' => 'Product deleted']);
    }

    public function manage(Request $request): JsonResponse
    {
        $query = Product::with(['category', 'variants.inventory']);

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('sku', 'like', "%{$search}%")
                  ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        $category = $request->query('category_id');
        if ($category) {
            $query->where('category_id', $category);
        }

        $products = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($products);
    }

    private function handleImage(Request $request): ?string
    {
        if ($request->hasFile('image_file')) {
            $file = $request->file('image_file');
            $filename = time() . '_' . Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) . '.' . $file->getClientOriginalExtension();
            $file->move(public_path('products'), $filename);
            return '/products/' . $filename;
        }

        if ($request->input('image_url')) {
            return $request->input('image_url');
        }

        return null;
    }
}
