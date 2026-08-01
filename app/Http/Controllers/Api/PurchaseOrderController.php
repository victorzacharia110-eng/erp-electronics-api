<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PurchaseOrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = PurchaseOrder::where('owner_id', $request->ownerId())->with(['items.productVariant.product', 'supplier']);

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        $orders = $query->orderBy('created_at', 'desc')->paginate(20);
        return response()->json($orders);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'supplier_name' => 'required_without:supplier_id|string|max:255',
            'supplier_contact' => 'nullable|string|max:255',
            'expected_date' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
            'items' => 'required|array|min:1',
            'items.*.product_variant_id' => 'required|exists:product_variants,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.unit_cost' => 'required|numeric|min:0',
        ]);

        $user = $request->user();
        $totalCost = 0;
        foreach ($validated['items'] as $item) {
            $totalCost += $item['quantity'] * $item['unit_cost'];
        }

        DB::beginTransaction();

        try {
            $supplierName = $validated['supplier_name'] ?? '';
            if (!empty($validated['supplier_id'])) {
                $supplier = Supplier::find($validated['supplier_id']);
                $supplierName = $supplier?->name ?? $supplierName;
            }

            $po = PurchaseOrder::create([
                'owner_id' => $request->ownerId(),
                'supplier_id' => $validated['supplier_id'] ?? null,
                'po_number' => PurchaseOrder::generatePONumber($user->id),
                'supplier_name' => $supplierName,
                'supplier_contact' => $validated['supplier_contact'] ?? null,
                'status' => 'draft',
                'total_cost' => $totalCost,
                'expected_date' => $validated['expected_date'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            foreach ($validated['items'] as $item) {
                $po->items()->create([
                    'product_variant_id' => $item['product_variant_id'],
                    'quantity' => $item['quantity'],
                    'unit_cost' => $item['unit_cost'],
                    'total' => $item['quantity'] * $item['unit_cost'],
                ]);
            }

            DB::commit();
            $po->load('items.productVariant.product');

            return response()->json($po, 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to create purchase order'], 500);
        }
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $po = PurchaseOrder::where('id', $id)
            ->where('owner_id', $request->ownerId())
            ->with('items.productVariant.product')
            ->firstOrFail();

        return response()->json($po);
    }

    public function receive(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $po = PurchaseOrder::where('id', $id)
            ->where('owner_id', $request->ownerId())
            ->where('status', 'ordered')
            ->with('items')
            ->firstOrFail();

        DB::beginTransaction();

        try {
            foreach ($po->items as $item) {
                $inventory = Inventory::where('product_variant_id', $item->product_variant_id)->first();
                if ($inventory) {
                    $inventory->increment('quantity_on_hand', $item->quantity);
                    $newQty = $inventory->fresh()->quantity_on_hand;
                } else {
                    $inventory = Inventory::create([
                        'product_variant_id' => $item->product_variant_id,
                        'quantity_on_hand' => $item->quantity,
                        'reorder_level' => 10,
                    ]);
                    $newQty = $item->quantity;
                }

                InventoryTransaction::create([
                    'owner_id' => $request->ownerId(),
                    'product_variant_id' => $item->product_variant_id,
                    'type' => 'purchase',
                    'quantity_change' => $item->quantity,
                    'quantity_after' => $newQty,
                    'unit_cost' => $item->unit_cost,
                    'reference_type' => 'purchase_order',
                    'reference_id' => $po->id,
                    'notes' => "PO {$po->po_number} received",
                    'created_by' => $user->id,
                ]);

                $item->update(['quantity_received' => $item->quantity]);
            }

            $cashAccount = Account::where('owner_id', $request->ownerId())->where('code', '1020')->first();
            $inventoryAccount = Account::where('owner_id', $request->ownerId())->where('code', '1200')->first();

            $journalEntry = null;
            if ($cashAccount && $inventoryAccount) {
                $journalEntry = JournalEntry::create([
                    'owner_id' => $request->ownerId(),
                    'reference' => JournalEntry::generateReference($user->id),
                    'date' => now()->toDateString(),
                    'description' => "Inventory purchase from {$po->supplier_name} ({$po->po_number})",
                    'status' => 'posted',
                    'posted_by' => $user->id,
                    'posted_at' => now(),
                ]);

                $journalEntry->lines()->create([
                    'account_id' => $inventoryAccount->id,
                    'debit' => $po->total_cost,
                    'credit' => 0,
                    'description' => "Inventory received: {$po->po_number}",
                ]);

                $journalEntry->lines()->create([
                    'account_id' => $cashAccount->id,
                    'debit' => 0,
                    'credit' => $po->total_cost,
                    'description' => "Payment for {$po->po_number}",
                ]);
            }

            $po->update([
                'status' => 'received',
                'received_date' => now(),
                'journal_entry_id' => $journalEntry?->id,
            ]);

            DB::commit();

            StockAlertController::checkLowStock($user->id);

            $po->load('items.productVariant.product');

            return response()->json([
                'purchase_order' => $po,
                'message' => 'Purchase order received and inventory updated',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to receive purchase order'], 500);
        }
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $po = PurchaseOrder::where('id', $id)
            ->where('owner_id', $request->ownerId())
            ->where('status', 'draft')
            ->firstOrFail();

        $po->delete();

        return response()->json(['message' => 'Purchase order deleted']);
    }

    public function supplierOrders(Request $request): JsonResponse
    {
        $user = $request->user();
        $supplier = Supplier::where('owner_id', $user->owner_id ?? $user->id)
            ->where('email', $user->email)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier profile not found'], 404);
        }

        $orders = PurchaseOrder::where('supplier_id', $supplier->id)
            ->with('items.productVariant.product')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($orders);
    }

    public function supplierShow(Request $request, string $id): JsonResponse
    {
        $user = $request->user();
        $supplier = Supplier::where('owner_id', $user->owner_id ?? $user->id)
            ->where('email', $user->email)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier profile not found'], 404);
        }

        $po = PurchaseOrder::where('id', $id)
            ->where('supplier_id', $supplier->id)
            ->with('items.productVariant.product')
            ->firstOrFail();

        return response()->json($po);
    }

    public function supplierUpdateStatus(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'required|in:ordered,received',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $supplier = Supplier::where('owner_id', $user->owner_id ?? $user->id)
            ->where('email', $user->email)
            ->first();

        if (!$supplier) {
            return response()->json(['message' => 'Supplier profile not found'], 404);
        }

        $po = PurchaseOrder::where('id', $id)
            ->where('supplier_id', $supplier->id)
            ->firstOrFail();

        $updateData = ['status' => $validated['status']];
        if ($validated['status'] === 'received') {
            $updateData['received_date'] = now();
        }
        if (!empty($validated['notes'])) {
            $updateData['notes'] = ($po->notes ? $po->notes . "\n" : '') . $validated['notes'];
        }

        $po->update($updateData);

        return response()->json([
            'purchase_order' => $po->fresh('items.productVariant.product'),
            'message' => 'Purchase order status updated',
        ]);
    }
}
