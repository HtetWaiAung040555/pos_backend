<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Inventory;
use App\Http\Resources\InventoryResource;
use App\Models\StockTransaction;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class InventoriesController extends Controller
{
    public function index()
    {
        $inventories = Inventory::with(['product', 'warehouse', 'createdBy', 'updatedBy'])->get();
        return InventoryResource::collection($inventories);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name'       => 'nullable|string|max:255',
            'qty'        => 'required|integer|min:0',
            'expired_date' => 'nullable|date',
            'product_id' => 'required|exists:products,id',
            'warehouse_id'  => 'nullable|exists:warehouses,id',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id'
        ]);

        DB::beginTransaction();
        try {
            $remainingQty = $request->qty;

            // 1️⃣ Offset negative inventory first
            $negativeInventories = Inventory::where('product_id', $request->product_id)
                ->where('warehouse_id', $request->warehouse_id)
                ->where('qty', '<', 0)
                ->orderBy('created_at')
                ->lockForUpdate()
                ->get();

            foreach ($negativeInventories as $negInv) {
                if ($remainingQty <= 0) break;

                $offsetQty = min(abs($negInv->qty), $remainingQty);

                $negInv->qty += $offsetQty;
                $negInv->updated_by = $request->created_by;
                $negInv->save();

                StockTransaction::create([
                    'inventory_id'    => $negInv->id,
                    'reference_id'    => $request->reference_id ?? null,
                    'reference_type'  => 'opening',
                    'quantity_change' => $offsetQty,
                    'type'            => 'in',
                    'created_by'      => $request->created_by
                ]);

                $remainingQty -= $offsetQty;
            }

            // 2️⃣ Remaining qty → new inventory batch
            if ($remainingQty > 0) {
                $inventory = Inventory::create([
                    'product_id'   => $request->product_id,
                    'warehouse_id' => $request->warehouse_id,
                    'expired_date'  => $request->expired_date,
                    'qty'          => $remainingQty,
                    'created_by'   => $request->created_by,
                    'updated_by' => $request->updated_by ?? $request->created_by
                ]);

                StockTransaction::create([
                    'inventory_id'    => $inventory->id,
                    'reference_id'    => $request->reference_id ?? null,
                    'reference_type'  => 'opening',
                    'quantity_change' => $remainingQty,
                    'type'            => 'in',
                    'created_by'      => $request->created_by
                ]);
            }

            DB::commit();
            return new InventoryResource($inventory->fresh(['product','warehouse','createdBy','updatedBy']));
        } catch(\Exception $e){
            DB::rollBack();
            return response()->json(['error' => 'Failed to create sale', 'details' => $e->getMessage()], 500);
        }
    }

    public function show(string $id)
    {
        $inventory = Inventory::with(['product','warehouse','createdBy','updatedBy'])->findOrFail($id);
        return new InventoryResource($inventory);
    }

    public function update(Request $request, string $id)
    {
        DB::beginTransaction();

        try {
            $inventory = Inventory::lockForUpdate()->findOrFail($id);

            $request->validate([
                'qty'           => 'sometimes|required|integer',
                'expired_date'  => 'sometimes|nullable|date',
                'updated_by'    => 'required|exists:users,id'
            ]);

            $hasOutTransaction = StockTransaction::where('inventory_id', $inventory->id)->where('reference_type', 'sale')->exists();

            if ($hasOutTransaction && $request->has('qty')) {
                return response()->json([
                    'error' => 'This inventory batch was already used. Quantity cannot be directly updated. Please use stock adjustment.'
                ], 422);
            }

            $inventory->update([
                'name'          => $request->name ?? $inventory->name,
                'expired_date'  => $request->expired_date ?? $inventory->expired_date,
                'updated_by'    => $request->updated_by
            ]);

            if ($request->has('qty')) {
                $oldQty = $inventory->qty;
                $newQty = $request->qty;
                $diff   = $newQty - $oldQty;

                if ($diff != 0) {
                    $inventory->qty = $newQty;
                    $inventory->save();

                    StockTransaction::create([
                        'inventory_id'    => $inventory->id,
                        'reference_type'  => 'opening_adjustment',
                        'quantity_change' => abs($diff),
                        'type'            => $diff > 0 ? 'in' : 'out',
                        'created_by'      => $request->updated_by
                    ]);
                }
            }

            DB::commit();

            return new InventoryResource(
                $inventory->fresh(['product','warehouse','createdBy','updatedBy'])
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update inventory',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        $request->validate([
            'void_by' => 'required|exists:users,id'
        ]);

        DB::beginTransaction();

        try {
            $inventory = Inventory::lockForUpdate()->findOrFail($id);

            // 1️⃣ Prevent double void
            if ($inventory->status === 'void') {
                return response()->json([
                    'error' => 'Inventory already voided'
                ], 422);
            }

            // 2️⃣ Block if used in SALE
            $usedInSale = StockTransaction::where('inventory_id', $inventory->id)
                ->where('type', 'out')
                ->where('reference_type', 'sale')
                ->exists();

            if ($usedInSale) {
                return response()->json([
                    'error' => 'Inventory was used in sale and cannot be voided'
                ], 422);
            }

            // 3️⃣ Reverse remaining stock
            if ($inventory->qty != 0) {
                StockTransaction::create([
                    'inventory_id'    => $inventory->id,
                    'reference_id'    => null,
                    'reference_type'  => 'opening_void',
                    'quantity_change' => abs($inventory->qty),
                    'type'            => $inventory->qty > 0 ? 'out' : 'in',
                    'created_by'      => $request->void_by
                ]);

                $inventory->qty = 0;
            }

            // 4️⃣ Mark inventory as VOID
            $inventory->update([
                'status'     => 'void',
                'updated_by' => $request->void_by,
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Inventory voided successfully'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error'   => 'Failed to void inventory',
                'details'=> $e->getMessage()
            ], 500);
        }
    }

    public function adjust(Request $request)
    {
        $request->validate([
            'inventory_id' => 'required|exists:inventories,id',
            'qty'          => 'required|integer|not_in:0',
            'reason'       => 'nullable|string|max:255',
            'created_by'   => 'required|exists:users,id'
        ]);

        DB::beginTransaction();

        try {
            $inventory = Inventory::lockForUpdate()->findOrFail($request->inventory_id);

            // Adjust qty (can go negative)
            $inventory->qty += $request->qty;
            $inventory->updated_by = $request->created_by;
            $inventory->save();

            StockTransaction::create([
                'inventory_id'    => $inventory->id,
                'reference_id'    => null,
                'reference_type'  => 'adjustment',
                'quantity_change' => $request->qty,
                'reason'          => $request->reason,
                'type'            => $request->qty > 0 ? 'in' : 'out',
                'created_by'      => $request->created_by
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Stock adjusted successfully'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'errors' => [
                    'message' => 'Adjustment failed'
                ],
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function saleproducts(Request $request){
        $warehouseId = $request->warehouse_id;

        $query = Inventory::query()
            ->select(
                'product_id',
                DB::raw('SUM(qty) as total_qty')
            )
            ->where('qty', '>', 0)
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
                    'product'    => $row->product,
                    'qty'        => (int) $row->total_qty,
                    'price'      => $row->product->price,
                ];
            })
        );
    }

}

// ->where('status_id', '!=', '8')