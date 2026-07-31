<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Exceptions\AccountingException;
use App\Models\Inventory;
use App\Models\InventoryTransaction;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\AccountingEntryService;
use App\Http\Controllers\Api\StockAlertController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InventoryController extends Controller
{
    public function __construct(
        private AccountingEntryService $entries = new AccountingEntryService()
    ) {}
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Inventory::with(['productVariant.product'])
            ->whereHas('productVariant', fn($q) => $q->where('is_active', true));

        if ($search = $request->query('search')) {
            $query->whereHas('productVariant', function ($q) use ($search) {
                $q->where('sku', 'like', "%{$search}%")
                    ->orWhere('color', 'like', "%{$search}%")
                    ->orWhereHas('product', fn($q2) => $q2->where('name', 'like', "%{$search}%"));
            });
        }

        if ($request->boolean('low_stock')) {
            $query->whereColumn('quantity_on_hand', '<=', 'reorder_level');
        }

        $inventory = $query->orderBy('quantity_on_hand', 'asc')->paginate(20);

        $inventory->getCollection()->transform(function ($item) {
            $item->is_low_stock = $item->quantity_on_hand <= $item->reorder_level;
            $item->stock_value = $item->quantity_on_hand * ($item->productVariant->cost_price ?? 0);
            return $item;
        });

        return response()->json($inventory);
    }

    public function adjust(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'product_variant_id' => 'required|exists:product_variants,id',
            'type' => 'required|in:adjustment,damage,opening',
            'quantity_change' => 'required|integer',
            'notes' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $inventory = Inventory::with('productVariant.product')
            ->where('product_variant_id', $validated['product_variant_id'])
            ->firstOrFail();

        $newQty = $inventory->quantity_on_hand + $validated['quantity_change'];

        if ($newQty < 0) {
            return response()->json(['message' => 'Insufficient stock'], 422);
        }

        DB::beginTransaction();

        try {
            $owner = $user->isOwner() ? $user : ($user->employeeProfile?->branch?->owner ?? $user);

            $inventory->update(['quantity_on_hand' => $newQty]);

            InventoryTransaction::create([
                'owner_id' => $owner->id,
                'product_variant_id' => $validated['product_variant_id'],
                'type' => $validated['type'],
                'quantity_change' => $validated['quantity_change'],
                'quantity_after' => $newQty,
                'unit_cost' => $inventory->productVariant->cost_price,
                'notes' => $validated['notes'],
                'created_by' => $user->id,
            ]);

            $this->entries->postInventoryAdjustment(
                $owner,
                $inventory->productVariant,
                (int) $validated['quantity_change'],
                $validated['type'],
                $validated['notes'] ?? null,
                $user
            );

            DB::commit();

            StockAlertController::checkLowStock($owner->id);

            return response()->json([
                'inventory' => $inventory->fresh('productVariant.product'),
                'message' => 'Stock adjusted successfully',
            ]);
        } catch (AccountingException $e) {
            DB::rollBack();
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'Failed to adjust stock'], 500);
        }
    }

    public function transactions(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = InventoryTransaction::where('owner_id', $user->id)
            ->with(['productVariant.product', 'creator']);

        if ($variantId = $request->query('product_variant_id')) {
            $query->where('product_variant_id', $variantId);
        }

        if ($type = $request->query('type')) {
            $query->where('type', $type);
        }

        $transactions = $query->orderBy('created_at', 'desc')->paginate(20);
        return response()->json($transactions);
    }

    public function lowStock(Request $request): JsonResponse
    {
        $user = $request->user();
        $lowStock = Inventory::with(['productVariant.product'])
            ->whereHas('productVariant', fn($q) => $q->where('is_active', true))
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')
            ->orderBy('quantity_on_hand', 'asc')
            ->get();

        $lowStock->each(function ($item) {
            $item->is_low_stock = true;
            $item->stock_value = $item->quantity_on_hand * ($item->productVariant->cost_price ?? 0);
        });

        return response()->json($lowStock);
    }

    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        $totalItems = Inventory::whereHas('productVariant', fn($q) => $q->where('is_active', true))->count();
        $totalStock = Inventory::whereHas('productVariant', fn($q) => $q->where('is_active', true))->sum('quantity_on_hand');
        $lowStockCount = Inventory::whereHas('productVariant', fn($q) => $q->where('is_active', true))
            ->whereColumn('quantity_on_hand', '<=', 'reorder_level')->count();
        $stockValue = Inventory::whereHas('productVariant', fn($q) => $q->where('is_active', true))
            ->join('product_variants', 'inventory.product_variant_id', '=', 'product_variants.id')
            ->selectRaw('SUM(inventory.quantity_on_hand * product_variants.cost_price) as total')
            ->first()->total ?? 0;

        return response()->json([
            'total_items' => $totalItems,
            'total_stock' => $totalStock,
            'low_stock_count' => $lowStockCount,
            'stock_value' => $stockValue,
        ]);
    }
}
