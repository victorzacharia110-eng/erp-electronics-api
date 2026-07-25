<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Commission;
use App\Models\InventoryTransaction;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Order;
use App\Models\ProductVariant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrderController extends Controller
{
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
            'branch_id' => 'nullable|exists:branches,id',
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

        $order = Order::with('items.productVariant.inventory')
            ->where('id', $orderId)
            ->firstOrFail();

        $oldStatus = $order->status;
        $newStatus = $validated['status'];

        $order->update([
            'status' => $newStatus,
            'handled_by' => $request->user()->id,
        ]);

        if ($newStatus === 'paid' && $oldStatus !== 'paid') {
            $ownerId = $order->branch ? $order->branch->owner_id : $request->user()->id;

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
                        'created_by' => $request->user()->id,
                    ]);
                }
            }

            $this->createRevenueEntry($order, $request->user());

            $handledBy = $order->handled_by ?? $request->user()->id;
            $handler = \App\Models\User::with('employeeProfile')->find($handledBy);
            if ($handler && $handler->role === 'employee' && $handler->employeeProfile && $handler->employeeProfile->commission_rate > 0) {
                $rate = $handler->employeeProfile->commission_rate;

                $costAmount = 0;
                foreach ($order->items as $item) {
                    $costAmount += ($item->productVariant->cost_price ?? 0) * $item->quantity;
                }
                $profitAmount = $order->subtotal - $costAmount;
                $commissionAmount = max(0, $profitAmount * ($rate / 100));

                Commission::create([
                    'owner_id' => $ownerId,
                    'employee_id' => $handler->id,
                    'order_id' => $order->id,
                    'order_amount' => $order->subtotal,
                    'cost_amount' => $costAmount,
                    'profit_amount' => $profitAmount,
                    'commission_rate' => $rate,
                    'commission_amount' => $commissionAmount,
                ]);
            }
        }

        if ($newStatus === 'cancelled' && in_array($oldStatus, ['paid', 'processing'])) {
            $ownerId = $order->branch ? $order->branch->owner_id : $request->user()->id;

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
                        'created_by' => $request->user()->id,
                    ]);
                }
            }

            Commission::where('order_id', $order->id)->where('status', 'pending')->delete();

            $this->createReversalEntry($order, $request->user());
        }

        return response()->json([
            'order' => $order->fresh(['items.productVariant.product', 'payments', 'handler', 'user']),
            'message' => "Order status updated to {$newStatus}",
        ]);
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

    private function createRevenueEntry(Order $order, $user): void
    {
        $ownerId = $order->branch ? $order->branch->owner_id : $user->id;
        $cashAccount = Account::where('owner_id', $ownerId)->where('code', '1020')->first();
        $salesRevenueAccount = Account::where('owner_id', $ownerId)->where('code', '4010')->first();
        $shippingRevenueAccount = Account::where('owner_id', $ownerId)->where('code', '4020')->first();
        $cogsAccount = Account::where('owner_id', $ownerId)->where('code', '5010')->first();
        $inventoryAccount = Account::where('owner_id', $ownerId)->where('code', '1200')->first();

        if (!$cashAccount || !$salesRevenueAccount) return;

        $cogsTotal = 0;
        foreach ($order->items as $item) {
            $costPrice = $item->productVariant->cost_price ?? 0;
            $cogsTotal += $costPrice * $item->quantity;
        }

        $lines = [
            ['account_id' => $cashAccount->id, 'debit' => $order->total, 'credit' => 0, 'description' => "Payment for {$order->order_number}"],
            ['account_id' => $salesRevenueAccount->id, 'debit' => 0, 'credit' => $order->subtotal, 'description' => "Sale: {$order->order_number}"],
        ];

        if ($order->shipping_cost > 0 && $shippingRevenueAccount) {
            $lines[] = ['account_id' => $shippingRevenueAccount->id, 'debit' => 0, 'credit' => $order->shipping_cost, 'description' => "Shipping: {$order->order_number}"];
        }

        if ($cogsTotal > 0 && $cogsAccount && $inventoryAccount) {
            $lines[] = ['account_id' => $cogsAccount->id, 'debit' => $cogsTotal, 'credit' => 0, 'description' => "COGS: {$order->order_number}"];
            $lines[] = ['account_id' => $inventoryAccount->id, 'debit' => 0, 'credit' => $cogsTotal, 'description' => "Inventory out: {$order->order_number}"];
        }

        $entry = JournalEntry::create([
            'owner_id' => $ownerId,
            'reference' => JournalEntry::generateReference($ownerId),
            'date' => now()->toDateString(),
            'description' => "Revenue from order {$order->order_number}",
            'status' => 'posted',
            'posted_by' => $user->id,
            'posted_at' => now(),
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);

        foreach ($lines as $line) {
            $entry->lines()->create($line);
        }
    }

    private function createReversalEntry(Order $order, $user): void
    {
        $ownerId = $order->branch ? $order->branch->owner_id : $user->id;
        $cashAccount = Account::where('owner_id', $ownerId)->where('code', '1020')->first();
        $salesRevenueAccount = Account::where('owner_id', $ownerId)->where('code', '4010')->first();
        $shippingRevenueAccount = Account::where('owner_id', $ownerId)->where('code', '4020')->first();
        $cogsAccount = Account::where('owner_id', $ownerId)->where('code', '5010')->first();
        $inventoryAccount = Account::where('owner_id', $ownerId)->where('code', '1200')->first();

        if (!$cashAccount || !$salesRevenueAccount) return;

        $cogsTotal = 0;
        foreach ($order->items as $item) {
            $costPrice = $item->productVariant->cost_price ?? 0;
            $cogsTotal += $costPrice * $item->quantity;
        }

        $lines = [
            ['account_id' => $salesRevenueAccount->id, 'debit' => $order->subtotal, 'credit' => 0, 'description' => "Reversal sale: {$order->order_number}"],
            ['account_id' => $cashAccount->id, 'debit' => 0, 'credit' => $order->total, 'description' => "Refund: {$order->order_number}"],
        ];

        if ($order->shipping_cost > 0 && $shippingRevenueAccount) {
            $lines[] = ['account_id' => $shippingRevenueAccount->id, 'debit' => $order->shipping_cost, 'credit' => 0, 'description' => "Reversal shipping: {$order->order_number}"];
        }

        if ($cogsTotal > 0 && $cogsAccount && $inventoryAccount) {
            $lines[] = ['account_id' => $inventoryAccount->id, 'debit' => $cogsTotal, 'credit' => 0, 'description' => "Inventory in: {$order->order_number}"];
            $lines[] = ['account_id' => $cogsAccount->id, 'debit' => 0, 'credit' => $cogsTotal, 'description' => "Reversal COGS: {$order->order_number}"];
        }

        $entry = JournalEntry::create([
            'owner_id' => $ownerId,
            'reference' => JournalEntry::generateReference($ownerId),
            'date' => now()->toDateString(),
            'description' => "Reversal for cancelled order {$order->order_number}",
            'status' => 'posted',
            'posted_by' => $user->id,
            'posted_at' => now(),
            'source_type' => 'order',
            'source_id' => $order->id,
        ]);

        foreach ($lines as $line) {
            $entry->lines()->create($line);
        }
    }
}
