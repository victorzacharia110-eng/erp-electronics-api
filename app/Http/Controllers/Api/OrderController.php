<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\AccountingException;
use App\Http\Controllers\Controller;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Services\AccountingEntryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class OrderController extends Controller
{
    public function __construct(
        private AccountingEntryService $entries = new AccountingEntryService()
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Order::with(['items.productVariant.product', 'payments', 'shippingAddress', 'handler', 'user'])
            ->where('status', '!=', 'pending_payment');

        if ($user->isCustomer()) {
            $query->where('user_id', $user->id);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($orders);
    }

    public function manage(Request $request): JsonResponse
    {
        $query = Order::with(['items.productVariant.product', 'payments', 'shippingAddress', 'handler', 'user', 'branch'])
            ->where('status', '!=', 'pending_payment');

        if ($ownerId = $request->ownerId()) {
            $query->where(function ($q) use ($ownerId) {
                $q->whereHas('branch', function ($qb) use ($ownerId) {
                    $qb->where('owner_id', $ownerId);
                })->orWhereNull('branch_id');
            });
        }

        $status = $request->query('status');
        if ($status) {
            $query->where('status', $status);
        }

        $branchId = $request->query('branch_id');
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }

        $search = $request->query('search');
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('order_number', 'like', "%{$search}%")
                    ->orWhereHas('user', function ($q2) use ($search) {
                        $q2->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
            });
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate(20);

        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'shipping_address_id' => 'required|exists:addresses,id',
            'notes' => 'nullable|string|max:500',
            'delivery_required' => 'nullable|boolean',
            'shipping_cost' => 'nullable|numeric|min:0',
            'branch_id' => ['nullable', Rule::exists('branches', 'id')->where('owner_id', $request->ownerId())],
        ]);

        $user = $request->user();
        $cart = Order::where('user_id', $user->id)
            ->where('status', 'pending_payment')
            ->with('items')
            ->first();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 422);
        }

        $deliveryRequired = $validated['delivery_required'] ?? false;
        $shippingCost = $deliveryRequired ? ($validated['shipping_cost'] ?? 5000) : 0;

        $cart->update([
            'order_number' => 'ORD-' . strtoupper(Str::random(10)),
            'status' => 'pending',
            'shipping_address_id' => $validated['shipping_address_id'],
            'notes' => $validated['notes'] ?? null,
            'delivery_required' => $deliveryRequired,
            'shipping_cost' => $shippingCost,
            'total' => $cart->subtotal + $shippingCost,
            'branch_id' => $validated['branch_id'] ?? null,
        ]);

        return response()->json($cart->fresh(['items.productVariant.product', 'shippingAddress']));
    }

    public function show(Request $request, string $orderId): JsonResponse
    {
        $user = $request->user();

        $query = Order::with(['items.productVariant.product', 'payments', 'shippingAddress', 'handler', 'user'])
            ->where('id', $orderId);

        if ($user->isCustomer()) {
            $query->where('user_id', $user->id);
        }

        $order = $query->firstOrFail();

        return response()->json($order);
    }

    public function updateStatus(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:pending,pending_payment,inactive,paid,processing,shipped,delivered,cancelled',
        ]);

        $user = $request->user();
        $newStatus = $validated['status'];

        try {
            DB::beginTransaction();

            $order = Order::with('items.productVariant.inventory')
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $oldStatus = $order->status;
            $order->update([
                'status' => $newStatus,
                'handled_by' => $user->id,
            ]);

            if ($newStatus === 'paid' && $oldStatus !== 'paid') {
                $this->confirmPaidOrder($order, $user);
            }

            if ($newStatus === 'cancelled' && in_array($oldStatus, ['paid', 'processing'])) {
                $this->reversePaidOrder($order, $user);
            }

            DB::commit();

            return response()->json([
                'order' => $order->fresh(['items.productVariant.product', 'payments', 'handler', 'user']),
                'message' => "Order status updated to {$newStatus}",
            ]);
        } catch (AccountingException $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to update order status'], 500);
        }
    }

    public function updateDelivery(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'tracking_number' => 'nullable|string|max:100',
            'delivery_notes' => 'nullable|string|max:500',
            'delivery_required' => 'nullable|boolean',
        ]);

        $order = Order::findOrFail($orderId);

        $updateData = array_filter($validated, fn($v) => $v !== null);
        $order->update($updateData);

        if (isset($validated['delivery_required']) && $validated['delivery_required'] && $order->status === 'paid') {
            $order->update(['status' => 'processing']);
        }

        return response()->json([
            'order' => $order->fresh(['shippingAddress']),
            'message' => 'Delivery details updated',
        ]);
    }

    public function returnItems(Request $request, string $orderId): JsonResponse
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.order_item_id' => 'required|exists:order_items,id',
            'items.*.quantity' => 'required|integer|min:1',
        ]);

        $user = $request->user();

        try {
            DB::beginTransaction();

            $order = Order::with(['items.productVariant.product.inventory', 'branch'])
                ->where('id', $orderId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!in_array($order->status, ['paid', 'processing', 'shipped', 'delivered'])) {
                throw new AccountingException('Only paid, processing, shipped, or delivered orders can be returned.');
            }

            $ownerId = $order->branch ? $order->branch->owner_id : $user->id;
            $returnedAmount = 0;
            $returnedCogs = 0;

            foreach ($validated['items'] as $returnItem) {
                $item = $order->items->firstWhere('id', $returnItem['order_item_id']);

                if (!$item) {
                    throw new AccountingException('One or more items do not belong to this order.');
                }

                $quantity = (int) $returnItem['quantity'];
                $available = $item->quantity - (int) $item->returned_quantity;

                if ($quantity > $available) {
                    throw new AccountingException('Return quantity exceeds the purchased quantity.');
                }

                $item->update(['returned_quantity' => (int) $item->returned_quantity + $quantity]);

                $inventory = $item->productVariant->inventory;
                if ($inventory) {
                    $inventory->increment('quantity_on_hand', $quantity);
                    InventoryTransaction::create([
                        'owner_id' => $ownerId,
                        'product_variant_id' => $item->product_variant_id,
                        'type' => 'return',
                        'quantity_change' => $quantity,
                        'quantity_after' => $inventory->fresh()->quantity_on_hand,
                        'unit_cost' => $item->productVariant->cost_price,
                        'reference_type' => 'order',
                        'reference_id' => $order->id,
                        'notes' => "Return: {$order->order_number}",
                        'created_by' => $user->id,
                    ]);
                }

                $this->entries->postReturn($order, $item, $quantity, $user);

                $unitAmount = $item->total / max(1, $item->quantity);
                $returnedAmount += $unitAmount * $quantity;
                $returnedCogs += ($item->productVariant->cost_price ?? 0) * $quantity;
            }

            $this->entries->adjustCommissionForReturn($order, $returnedAmount, $returnedCogs, $user);

            $fullyReturned = $order->items->every(fn ($item) => (int) $item->returned_quantity >= $item->quantity);
            if ($fullyReturned) {
                $order->update(['status' => 'cancelled']);
            }

            DB::commit();

            return response()->json([
                'order' => $order->fresh(['items.productVariant.product', 'payments', 'handler', 'user']),
                'message' => 'Return processed successfully',
            ]);
        } catch (AccountingException $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to process return'], 500);
        }
    }

    private function confirmPaidOrder(Order $order, $user): void
    {
        $ownerId = $order->branch ? $order->branch->owner_id : $user->id;

        foreach ($order->items as $item) {
            $inventory = $item->productVariant->inventory;
            if ($inventory) {
                $inventory->decrement('quantity_on_hand', $item->quantity);
                InventoryTransaction::create([
                    'owner_id' => $ownerId,
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'sale',
                    'quantity_change' => -$item->quantity,
                    'quantity_after' => $inventory->fresh()->quantity_on_hand,
                    'unit_cost' => $item->productVariant->cost_price,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'notes' => "Sale: {$order->order_number}",
                    'created_by' => $user->id,
                ]);
            }
        }

        $cogsTotal = $this->entries->computeCogsTotal($order);

        $this->entries->postSale($order, $user);

        $this->entries->createCommission($order, $user, $cogsTotal);
    }

    private function reversePaidOrder(Order $order, $user): void
    {
        $ownerId = $order->branch ? $order->branch->owner_id : $user->id;

        foreach ($order->items as $item) {
            $inventory = $item->productVariant->inventory;
            if ($inventory) {
                $inventory->increment('quantity_on_hand', $item->quantity);
                InventoryTransaction::create([
                    'owner_id' => $ownerId,
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'return',
                    'quantity_change' => $item->quantity,
                    'quantity_after' => $inventory->fresh()->quantity_on_hand,
                    'unit_cost' => $item->productVariant->cost_price,
                    'reference_type' => 'order',
                    'reference_id' => $order->id,
                    'notes' => "Return: {$order->order_number}",
                    'created_by' => $user->id,
                ]);
            }
        }

        $this->entries->reverseSale($order, $user);

        $this->entries->reverseCommissions($order, $user);
    }
}
