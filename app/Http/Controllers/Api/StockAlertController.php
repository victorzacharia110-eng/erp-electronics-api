<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StockAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StockAlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = StockAlert::where('owner_id', $user->id)
            ->with('productVariant.product');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $alerts = $query->orderBy('created_at', 'desc')->paginate(20);
        return response()->json($alerts);
    }

    public function count(Request $request): JsonResponse
    {
        $count = StockAlert::where('owner_id', $request->user()->id)
            ->where('status', 'active')
            ->count();

        return response()->json(['count' => $count]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $alert = StockAlert::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->where('status', 'active')
            ->firstOrFail();

        $alert->update(['status' => 'acknowledged']);
        return response()->json(['message' => 'Alert acknowledged']);
    }

    public function resolve(Request $request, string $id): JsonResponse
    {
        $alert = StockAlert::where('id', $id)
            ->where('owner_id', $request->user()->id)
            ->firstOrFail();

        $alert->update(['status' => 'resolved']);
        return response()->json(['message' => 'Alert resolved']);
    }

    public static function checkLowStock(int $ownerId): void
    {
        $inventory = \App\Models\Inventory::with('productVariant.product')
            ->whereHas('productVariant', fn($q) => $q->where('is_active', true))
            ->get();

        foreach ($inventory as $item) {
            $variant = $item->productVariant;
            $productName = $variant->product->name ?? 'Unknown';
            $variantName = trim(($variant->color ?? '') . ' ' . ($variant->storage ?? ''));

            if ($item->quantity_on_hand <= 0) {
                $existing = StockAlert::where('owner_id', $ownerId)
                    ->where('product_variant_id', $variant->id)
                    ->where('type', 'out_of_stock')
                    ->where('status', 'active')
                    ->first();

                if (!$existing) {
                    StockAlert::create([
                        'owner_id' => $ownerId,
                        'product_variant_id' => $variant->id,
                        'type' => 'out_of_stock',
                        'current_quantity' => $item->quantity_on_hand,
                        'reorder_level' => $item->reorder_level,
                        'message' => "{$productName} ({$variantName}) is out of stock",
                    ]);
                }
            } elseif ($item->quantity_on_hand <= $item->reorder_level) {
                $existing = StockAlert::where('owner_id', $ownerId)
                    ->where('product_variant_id', $variant->id)
                    ->where('type', 'low_stock')
                    ->where('status', 'active')
                    ->first();

                if (!$existing) {
                    StockAlert::create([
                        'owner_id' => $ownerId,
                        'product_variant_id' => $variant->id,
                        'type' => 'low_stock',
                        'current_quantity' => $item->quantity_on_hand,
                        'reorder_level' => $item->reorder_level,
                        'message' => "{$productName} ({$variantName}) is low on stock ({$item->quantity_on_hand} remaining, reorder at {$item->reorder_level})",
                    ]);
                }
            }
        }
    }
}
