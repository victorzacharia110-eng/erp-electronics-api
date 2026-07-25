<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CartController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $cart = $this->getOrCreateCart($request->user());

        return response()->json($cart->fresh(['items.productVariant.product', 'items.productVariant.inventory']));
    }

    public function add(Request $request): JsonResponse
    {
        $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        $cart = $this->getOrCreateCart($user);
        $variant = ProductVariant::with('inventory')->findOrFail($request->product_variant_id);

        if (!$variant->inventory || $variant->inventory->quantity_on_hand < $request->quantity) {
            return response()->json(['message' => 'Insufficient stock'], 422);
        }

        $existingItem = $cart->items()
            ->where('product_variant_id', $variant->id)
            ->first();

        if ($existingItem) {
            $existingItem->update([
                'quantity' => $existingItem->quantity + $request->quantity,
                'total' => ($existingItem->quantity + $request->quantity) * $existingItem->unit_price,
            ]);
        } else {
            $cart->items()->create([
                'product_variant_id' => $variant->id,
                'quantity' => $request->quantity,
                'unit_price' => $variant->price,
                'total' => $request->quantity * $variant->price,
            ]);
        }

        $this->recalculateCart($cart);

        return response()->json($cart->fresh(['items.productVariant.product', 'items.productVariant.inventory']));
    }

    public function update(Request $request, string $itemId): JsonResponse
    {
        $request->validate([
            'quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();
        $cart = $this->getOrCreateCart($user);
        $item = $cart->items()->findOrFail($itemId);

        $variant = ProductVariant::with('inventory')->find($item->product_variant_id);

        if (!$variant->inventory || $variant->inventory->quantity_on_hand < $request->quantity) {
            return response()->json(['message' => 'Insufficient stock'], 422);
        }

        $item->update([
            'quantity' => $request->quantity,
            'total' => $request->quantity * $item->unit_price,
        ]);

        $this->recalculateCart($cart);

        return response()->json($cart->fresh(['items.productVariant.product', 'items.productVariant.inventory']));
    }

    public function remove(Request $request, string $itemId): JsonResponse
    {
        $cart = $this->getOrCreateCart($request->user());
        $cart->items()->where('id', $itemId)->delete();

        $this->recalculateCart($cart);

        return response()->json($cart->fresh(['items.productVariant.product', 'items.productVariant.inventory']));
    }

    public function clear(Request $request): JsonResponse
    {
        $cart = $this->getOrCreateCart($request->user());
        $cart->items()->delete();
        $this->recalculateCart($cart);

        return response()->json(['message' => 'Cart cleared']);
    }

    private function getOrCreateCart($user): Order
    {
        return Order::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'pending_payment'],
            [
                'order_number' => 'CART-' . strtoupper(Str::random(10)),
                'subtotal' => 0,
                'shipping_cost' => 0,
                'total' => 0,
            ]
        );
    }

    private function recalculateCart(Order $cart): void
    {
        $subtotal = $cart->items()->sum('total');
        $cart->update([
            'subtotal' => $subtotal,
            'total' => $subtotal + $cart->shipping_cost,
        ]);
    }
}
