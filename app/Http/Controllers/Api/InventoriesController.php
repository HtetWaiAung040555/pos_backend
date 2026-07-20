<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InventoryResource;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\ProductUnit;
use App\Models\Promotion;
use App\Models\PromotionFocAllocation;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

use function Symfony\Component\Clock\now;

class InventoriesController extends Controller
{
    public function focAvailability(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'branch_ids' => ['required', 'array', 'min:1'],
            'branch_ids.*' => ['required', 'integer', 'distinct', 'exists:branches,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.product_unit_id' => ['required', 'integer', 'exists:product_units,id'],
            'promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'FOC availability validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $validated = $validator->validated();
        $branchIds = array_map('intval', $validated['branch_ids']);
        $items = collect($validated['items'])
            ->map(fn (array $item) => [
                'product_id' => (int) $item['product_id'],
                'product_unit_id' => (int) $item['product_unit_id'],
            ]);

        $branches = Branch::with('warehouse')
            ->whereIn('id', array_values(array_unique($branchIds)))
            ->get()
            ->keyBy('id');

        $productUnits = ProductUnit::with(['product', 'unit'])
            ->whereIn('id', $items->pluck('product_unit_id')->unique()->values())
            ->get()
            ->keyBy('id');

        $errors = [];

        foreach ($branchIds as $index => $branchId) {
            if (empty($branches->get($branchId)?->warehouse_id)) {
                $errors["branch_ids.{$index}"][] = 'The selected branch does not have an assigned warehouse.';
            }
        }

        foreach ($items as $index => $item) {
            $productUnit = $productUnits->get($item['product_unit_id']);

            if (! $productUnit || (int) $productUnit->product_id !== $item['product_id']) {
                $errors["items.{$index}.product_unit_id"][] = 'The selected product unit does not belong to this product.';

                continue;
            }

            if ((float) $productUnit->conversion_to_base <= 0) {
                $errors["items.{$index}.product_unit_id"][] = 'The selected product unit has an invalid base-unit conversion.';
            }
        }

        $promotionId = isset($validated['promotion_id'])
            ? (int) $validated['promotion_id']
            : null;

        if ($promotionId !== null) {
            $promotion = Promotion::find($promotionId);

            if ($promotion?->promo_type !== 'FOC') {
                $errors['promotion_id'][] = 'The selected promotion is not an FOC promotion.';
            }
        }

        if (! empty($errors)) {
            return response()->json([
                'message' => 'FOC availability validation failed.',
                'errors' => $errors,
            ], 422);
        }

        $warehouseIds = $branches
            ->pluck('warehouse_id')
            ->filter()
            ->map(fn ($warehouseId) => (int) $warehouseId)
            ->unique()
            ->values();
        $productIds = $items->pluck('product_id')->unique()->values();

        $unreservedByPool = Inventory::query()
            ->select(['warehouse_id', 'product_id'])
            ->selectRaw('SUM(qty) as available_base_qty')
            ->whereIn('warehouse_id', $warehouseIds)
            ->whereIn('product_id', $productIds)
            ->whereNull('void_at')
            ->where('qty', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expired_date')
                    ->orWhereDate('expired_date', '>=', today()->toDateString());
            })
            ->groupBy('warehouse_id', 'product_id')
            ->get()
            ->mapWithKeys(fn ($inventory) => [
                $this->focAvailabilityPoolKey($inventory->warehouse_id, $inventory->product_id) => (float) $inventory->available_base_qty,
            ]);

        $currentAllocationByPool = collect();

        if ($promotionId !== null) {
            $currentAllocationByPool = PromotionFocAllocation::query()
                ->select(['allocated_warehouse_id', 'product_id'])
                ->selectRaw('SUM(COALESCE(allocated_base_qty, allocated_qty)) as allocated_base_qty')
                ->where('promotion_id', $promotionId)
                ->whereIn('allocated_warehouse_id', $warehouseIds)
                ->whereIn('product_id', $productIds)
                ->groupBy('allocated_warehouse_id', 'product_id')
                ->get()
                ->mapWithKeys(fn ($allocation) => [
                    $this->focAvailabilityPoolKey($allocation->allocated_warehouse_id, $allocation->product_id) => (float) $allocation->allocated_base_qty,
                ]);
        }

        $data = collect($branchIds)
            ->flatMap(function (int $branchId) use ($branches, $items, $productUnits, $unreservedByPool, $currentAllocationByPool) {
                $branch = $branches->get($branchId);
                $warehouseId = (int) $branch->warehouse_id;

                return $items->map(function (array $item) use ($branch, $warehouseId, $productUnits, $unreservedByPool, $currentAllocationByPool) {
                    $productUnit = $productUnits->get($item['product_unit_id']);
                    $poolKey = $this->focAvailabilityPoolKey($warehouseId, $item['product_id']);
                    $availableBaseQty = max(
                        0,
                        (float) $unreservedByPool->get($poolKey, 0)
                            + (float) $currentAllocationByPool->get($poolKey, 0)
                    );
                    $conversionToBase = (float) $productUnit->conversion_to_base;

                    return [
                        'branch_id' => (int) $branch->id,
                        'branch_name' => $branch->name,
                        'warehouse_id' => $warehouseId,
                        'warehouse_name' => $branch->warehouse->name,
                        'product_id' => $item['product_id'],
                        'product_name' => $productUnit->product->name,
                        'product_unit_id' => $item['product_unit_id'],
                        'unit_name' => $productUnit->unit->name,
                        'available_qty' => (int) floor(($availableBaseQty + 0.000001) / $conversionToBase),
                        'available_base_qty' => $this->normalizeFocAvailabilityQuantity($availableBaseQty),
                    ];
                });
            })
            ->values();

        return response()->json(['data' => $data]);
    }

    public function index()
    {
        $inventories = Inventory::with(['product', 'warehouse', 'createdBy', 'updatedBy'])->get();

        return InventoryResource::collection($inventories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'nullable|string|max:255',
            'qty' => 'required|integer|min:0',
            'foc_qty' => 'nullable|integer|min:0',
            'expired_date' => 'nullable|date',
            'product_id' => 'required|exists:products,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        DB::beginTransaction();
        try {
            $remainingQty = $request->qty;

            // Offset negative inventory first
            $negativeInventories = Inventory::where('product_id', $request->product_id)
                ->where('warehouse_id', $request->warehouse_id)
                ->where('qty', '<', 0)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            foreach ($negativeInventories as $negInv) {
                if ($remainingQty <= 0) {
                    break;
                }

                $offsetQty = min(abs($negInv->qty), $remainingQty);

                $negInv->qty += $offsetQty;
                $negInv->updated_by = $request->created_by;
                $negInv->save();

                StockTransaction::create([
                    'inventory_id' => $negInv->id,
                    'reference_id' => $negInv->id,
                    'reference_date' => now(),
                    'reference_type' => 'opening',
                    'quantity_change' => $offsetQty,
                    'type' => 'in',
                    'created_by' => $request->created_by,
                ]);

                $remainingQty -= $offsetQty;
            }

            // Remaining qty -> new inventory batch
            if ($remainingQty > 0) {
                $inventory = Inventory::create([
                    'product_id' => $request->product_id,
                    'warehouse_id' => $request->warehouse_id,
                    'expired_date' => $request->expired_date,
                    'qty' => $remainingQty,
                    'foc_qty' => $request->foc_qty ?? 0,
                    'created_by' => $request->created_by,
                    'updated_by' => $request->updated_by ?? $request->created_by,
                ]);

                StockTransaction::create([
                    'inventory_id' => $inventory->id,
                    'reference_id' => $inventory->id,
                    'reference_type' => 'opening',
                    'reference_date' => now(),
                    'quantity_change' => $remainingQty,
                    'type' => 'in',
                    'created_by' => $request->created_by,
                ]);
            }

            DB::commit();

            return new InventoryResource($inventory->fresh(['product', 'warehouse', 'createdBy', 'updatedBy']));
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(['error' => 'Failed to create sale', 'details' => $e->getMessage()], 500);
        }
    }

    public function show(string $id)
    {
        $inventory = Inventory::with(['product', 'warehouse', 'createdBy', 'updatedBy'])->findOrFail($id);

        return new InventoryResource($inventory);
    }

    public function update(Request $request, string $id)
    {
        DB::beginTransaction();

        try {
            $inventory = Inventory::lockForUpdate()->findOrFail($id);

            $request->validate([
                'qty' => 'sometimes|required|integer',
                'foc_qty' => 'sometimes|required|integer|min:0',
                'expired_date' => 'sometimes|nullable|date',
                'updated_by' => 'required|exists:users,id',
            ]);

            $hasOutTransaction = StockTransaction::where('inventory_id', $inventory->id)->where('reference_type', 'sale')->exists();

            if ($hasOutTransaction && ($request->has('qty') || $request->has('foc_qty'))) {
                return response()->json([
                    'error' => 'This inventory batch was already used. Quantities cannot be directly updated. Please use stock adjustment.',
                ], 422);
            }

            $inventory->update([
                'name' => $request->name ?? $inventory->name,
                'expired_date' => $request->expired_date ?? $inventory->expired_date,
                'updated_by' => $request->updated_by,
            ]);

            if ($request->has('qty')) {
                $oldQty = $inventory->qty;
                $newQty = $request->qty;
                $diff = $newQty - $oldQty;

                if ($diff != 0) {
                    $inventory->qty = $newQty;
                    $inventory->save();

                    StockTransaction::create([
                        'inventory_id' => $inventory->id,
                        'reference_type' => 'opening_adjustment',
                        'reference_date' => now(),
                        'reference_id' => $inventory->id,
                        'quantity_change' => abs($diff),
                        'type' => $diff > 0 ? 'in' : 'out',
                        'created_by' => $request->updated_by,
                    ]);
                }
            }

            if ($request->has('foc_qty')) {
                $oldFocQty = (int) $inventory->foc_qty;
                $newFocQty = (int) $request->foc_qty;
                $focDiff = $newFocQty - $oldFocQty;

                if ($focDiff !== 0) {
                    $inventory->foc_qty = $newFocQty;
                    $inventory->save();

                    StockTransaction::create([
                        'inventory_id' => $inventory->id,
                        'reference_type' => 'foc_opening_adjustment',
                        'reference_date' => now(),
                        'reference_id' => $inventory->id,
                        'quantity_change' => abs($focDiff),
                        'type' => $focDiff > 0 ? 'in' : 'out',
                        'created_by' => $request->updated_by,
                    ]);
                }
            }

            DB::commit();

            return new InventoryResource(
                $inventory->fresh(['product', 'warehouse', 'createdBy', 'updatedBy'])
            );

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to update inventory',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        $request->validate([
            'void_by' => 'required|exists:users,id',
        ]);

        DB::beginTransaction();

        try {
            $inventory = Inventory::lockForUpdate()->findOrFail($id);

            // Prevent double void
            if ($inventory->status === 'void') {
                return response()->json([
                    'error' => 'Inventory already voided',
                ], 422);
            }

            // Block if used in SALE
            $usedInSale = StockTransaction::where('inventory_id', $inventory->id)
                ->where('type', 'out')
                ->where('reference_type', 'sale')
                ->exists();

            if ($usedInSale) {
                return response()->json([
                    'error' => 'Inventory was used in sale and cannot be voided',
                ], 422);
            }

            // Reverse remaining stock
            if ($inventory->qty != 0) {
                StockTransaction::create([
                    'inventory_id' => $inventory->id,
                    'reference_id' => null,
                    'reference_type' => 'opening_void',
                    'reference_date' => now(),
                    'quantity_change' => abs($inventory->qty),
                    'type' => $inventory->qty > 0 ? 'out' : 'in',
                    'created_by' => $request->void_by,
                ]);

                $inventory->qty = 0;
            }

            // Mark inventory as VOID
            $inventory->update([
                'status' => 'void',
                'updated_by' => $request->void_by,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Inventory voided successfully',
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to void inventory',
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function adjust(Request $request)
    {
        $request->validate([
            'inventory_id' => 'required|exists:inventories,id',
            'qty' => 'required|integer|not_in:0',
            'reason' => 'nullable|string|max:255',
            'adjust_date' => 'nullable|date',
            'type' => 'nullable|in:in,out',
            'stock_type' => 'nullable|in:regular,foc',
            'created_by' => 'required|exists:users,id',
        ]);

        DB::beginTransaction();

        try {
            $inventory = Inventory::lockForUpdate()->findOrFail($request->inventory_id);
            $stockType = $request->stock_type ?? 'regular';

            // Adjust qty (can go negative)
            if ($request->type === 'in') {
                if ($stockType === 'foc') {
                    $inventory->foc_qty += abs($request->qty);
                } else {
                    $inventory->qty += abs($request->qty);
                }
            } else {
                if ($stockType === 'foc') {
                    $inventory->foc_qty -= abs($request->qty);
                } else {
                    $inventory->qty -= abs($request->qty);
                }
            }
            $inventory->updated_by = $request->created_by;
            $inventory->save();

            StockTransaction::create([
                'inventory_id' => $inventory->id,
                'reference_id' => null,
                'reference_type' => $stockType === 'foc' ? 'foc_adjustment' : 'adjustment',
                'reference_date' => $request->adjust_date ?? now(),
                'quantity_change' => $request->qty,
                'reason' => $request->reason,
                'type' => $request->type ?? ($request->qty > 0 ? 'in' : 'out'),
                'created_by' => $request->created_by,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Stock adjusted successfully',
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'errors' => [
                    'message' => 'Adjustment failed',
                ],
                'details' => $e->getMessage(),
            ], 500);
        }
    }

    public function saleproducts(Request $request)
    {
        $warehouseId = $request->warehouse_id;

        $query = Inventory::query()
            ->select(
                'product_id',
                DB::raw('SUM(qty) as total_qty'),
                DB::raw('SUM(foc_qty) as total_foc_qty')
            )
            ->where(function ($q) {
                $q->where('qty', '>', 0)
                    ->orWhere('foc_qty', '>', 0);
            })
            ->with('product')
            ->groupBy('product_id');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $products = $query->get();

        return response()->json(
            $products->map(function ($row) {
                return [
                    'product_id' => $row->product_id,
                    'product' => $row->product,
                    'qty' => (int) $row->total_qty,
                    'foc_qty' => (int) $row->total_foc_qty,
                    'price' => $row->product->price,
                ];
            })
        );
    }

    private function focAvailabilityPoolKey($warehouseId, $productId): string
    {
        return (int) $warehouseId.':'.(int) $productId;
    }

    private function normalizeFocAvailabilityQuantity(float $quantity): int|float
    {
        $rounded = round($quantity, 6);

        return abs($rounded - round($rounded)) < 0.000001
            ? (int) round($rounded)
            : $rounded;
    }
}

// ->where('status_id', '!=', '8')
