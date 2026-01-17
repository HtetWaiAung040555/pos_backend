<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseReturnResource;
use App\Models\Inventory;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnDetail;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Symfony\Component\Clock\now;

class PurchaseReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = PurchaseReturn::with([
            "supplier",
            "status",
            "warehouse",
            "paymentMethod",
            "details.product",
            "createdBy",
            "updatedBy",
        ]);

        if ($request->filled("supplier_id")) {
            $query->where("supplier_id", $request->supplier_id);
        }

        if ($request->filled("status_id")) {
            $query->where("status_id", $request->status_id);
        }

        if ($request->filled("warehouse_id")) {
            $query->where("warehouse_id", $request->warehouse_id);
        }

        if ($request->filled("start_date") && $request->filled("end_date")) {
            $query->whereBetween("return_date", [
                $request->start_date,
                $request->end_date,
            ]);
        } elseif ($request->filled("start_date")) {
            $query->whereDate("return_date", ">=", $request->start_date);
        } elseif ($request->filled("end_date")) {
            $query->whereDate("return_date", "<=", $request->end_date);
        }

        return PurchaseReturnResource::collection($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            "purchase_id" => "required|exists:purchases,id",
            "warehouse_id" => "required|exists:warehouses,id",
            "supplier_id" => "required|exists:suppliers,id",
            "payment_id" => "required|exists:payment_methods,id",
            "remark" => "nullable|string|max:1000",
            "created_by" => "required|exists:users,id",
            "updated_by" => "nullable|exists:users,id",
            "return_date" => "nullable|date",
            "products" => "required|array|min:1",
            "products.*.purchase_detail_id" => "required|exists:purchase_details,id",
            "products.*.quantity" => "required|integer|min:1",
        ]);

        DB::beginTransaction();

        try {
            // Lock purchase row (important for concurrency)
            $purchase = Purchase::with("details")
                ->lockForUpdate()
                ->findOrFail($request->purchase_id);

            // Only confirmed purchases can be returned
            if ($purchase->status_id != 7) {
                throw ValidationException::withMessages([
                    "purchase" => "Only confirmed purchases can be returned",
                ]);
            }

            // Create Purchase Return (header)
            $purchaseReturn = PurchaseReturn::create([
                "purchase_id" => $purchase->id,
                "warehouse_id" => $request->warehouse_id,
                "supplier_id" => $request->supplier_id,
                "payment_id" => $request->payment_id,
                "status_id" => $request->status_id,
                "remark" => $request->remark,
                "return_date" => $request->return_date ?? now(),
                "total_amount" => 0,
                "created_by" => $request->created_by,
                "updated_by" => $request->updated_by ?? $request->created_by,
            ]);

            $totalReturnAmount = 0;

            // Loop return products
            foreach ($request->products as $item) {
                // Get original purchase detail
                $purchaseDetail = $purchase->details->where("id", $item["purchase_detail_id"])->firstOrFail();

                // Already returned qty
                $alreadyReturned = PurchaseReturnDetail::where("purchase_detail_id", $purchaseDetail->id)->sum("quantity");

                $availableQty = $purchaseDetail->quantity - $alreadyReturned;

                // Validate return qty
                if ($item["quantity"] > $availableQty) {
                    return response()->json(
                        [
                            "error" => "Return quantity exceeds sold quantity",
                        ],
                        422
                    );
                }

                // Calculate line total
                $lineTotal = $item["quantity"] * $purchaseDetail->price;

                // Create Purchase Return Detail
                PurchaseReturnDetail::create([
                    "purchase_return_id" => $purchaseReturn->id,
                    "purchase_detail_id" => $purchaseDetail->id,
                    "inventory_id" => $purchaseDetail->inventory_id,
                    "product_id" => $purchaseDetail->product_id,
                    "quantity" => $item["quantity"],
                    "price" => $purchaseDetail->price,
                    "total" => $lineTotal
                ]);

                $inventory = Inventory::find($purchaseDetail->inventory_id);

                if ($inventory) {
                    $inventory->qty -= $item["quantity"];
                    $inventory->save();
                }

                // Insert stock transaction
                StockTransaction::create([
                    'inventory_id' => $inventory->id ?? null,
                    'reference_id' => $purchaseReturn->id,
                    'reference_type' => 'purchase_return',
                    'reference_date' => $request->return_date ?? now(),
                    'quantity_change' => $item["quantity"],
                    'type' => 'out',
                    "created_by" => $request->created_by
                ]);

                $totalReturnAmount += $lineTotal;
            }

            // Update total amount
            $purchaseReturn->update([
                "total_amount" => $totalReturnAmount,
            ]);

            DB::commit();

            // Return resource (same style as PurchasesController)
            return new PurchaseReturnResource(
                $purchaseReturn->fresh([
                    "supplier",
                    "status",
                    "warehouse",
                    "paymentMethod",
                    "details.product",
                    "createdBy",
                    "updatedBy",
                ]),
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(
                [
                    "error" => "Failed to create purchase return",
                    "details" => $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function show(string $id)
    {
        $purchase_return = PurchaseReturn::with([
            "supplier",
            "status",
            "paymentMethod",
            "details.product",
            "createdBy",
            "updatedBy",
        ])->findOrFail($id);
        return new PurchaseReturnResource($purchase_return);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            "remark" => "nullable|string|max:1000",
            "updated_by" => "required|exists:users,id",
            "return_date" => "nullable|date",
            "products" => "required|array|min:1",
            "products.*.purchase_detail_id" => "required|exists:purchase_details,id",
            "products.*.quantity" => "required|integer|min:1",
        ]);

        DB::beginTransaction();

        try {
            // Lock purchase return
            $purchaseReturn = PurchaseReturn::with(["details", "details.purchaseDetail"])
                ->lockForUpdate()
                ->findOrFail($id);

            $purchase = Purchase::with("details")
                ->lockForUpdate()
                ->findOrFail($purchaseReturn->purchase_id);

            // ROLLBACK previous return stock (same inventory_id only)
            foreach ($purchaseReturn->details as $detail) {
                $inventory = Inventory::lockForUpdate()->find($detail->inventory_id);

                if (!$inventory) {
                    throw new \Exception("Inventory not found for rollback");
                }

                $inventory->qty += $detail->quantity;
                $inventory->updated_by = $request->updated_by;
                $inventory->save();

                StockTransaction::create([
                    "inventory_id" => $inventory->id,
                    "reference_id" => $purchaseReturn->id,
                    "reference_type" => "purchase_return_update",
                    'reference_date' => $request->return_date ?? $purchaseReturn->return_date,
                    "quantity_change" => $detail->quantity,
                    "type" => "in",
                    "created_by" => $request->updated_by,
                ]);
            }

            // Remove old return details
            PurchaseReturnDetail::where("purchase_return_id", $purchaseReturn->id)->delete();

            //Re-apply return (same inventory_id only)
            $totalReturnAmount = 0;

            foreach ($request->products as $item) {
                $purchaseDetail = $purchase->details
                    ->where("id", $item["purchase_detail_id"])
                    ->firstOrFail();

                // already returned except this return
                $alreadyReturned = PurchaseReturnDetail::where("purchase_detail_id", $purchaseDetail->id)
                    ->where("purchase_return_id", "!=", $purchaseReturn->id)
                    ->sum("quantity");

                $availableQty = $purchaseDetail->quantity - $alreadyReturned;

                if ($item["quantity"] > $availableQty) {
                    throw ValidationException::withMessages([
                        "quantity" => "Return quantity exceeds sold quantity",
                    ]);
                }

                $lineTotal = $item["quantity"] * ($purchaseDetail->discount_price > 0 ? $purchaseDetail->discount_price : $purchaseDetail->price);

                // save return detail
                PurchaseReturnDetail::create([
                    "purchase_return_id" => $purchaseReturn->id,
                    "purchase_detail_id" => $purchaseDetail->id,
                    "inventory_id" => $purchaseDetail->inventory_id,
                    "product_id" => $purchaseDetail->product_id,
                    "quantity" => $item["quantity"],
                    "price" => $purchaseDetail->price,
                    "total" => $lineTotal,
                ]);

                // 🔁 Put stock back to SAME inventory
                $inventory = Inventory::lockForUpdate()->find($purchaseDetail->inventory_id);

                if (!$inventory) {
                    throw new \Exception("Inventory not found for return");
                }

                $inventory->qty -= $item["quantity"];
                $inventory->updated_by = $request->updated_by;
                $inventory->save();

                StockTransaction::create([
                    "inventory_id" => $inventory->id,
                    "reference_id" => $purchaseReturn->id,
                    "reference_type" => "purchase_return",
                    "reference_date" => $request->return_date ?? $purchaseReturn->return_date,
                    "quantity_change" => $item["quantity"],
                    "type" => "out",
                    "created_by" => $request->updated_by,
                ]);

                $totalReturnAmount += $lineTotal;
            }

            // Update
            $purchaseReturn->update([
                "remark" => $request->remark,
                "return_date" => $request->return_date ?? $purchaseReturn->return_date,
                "payment_id" => $request->payment_id ?? $purchaseReturn->payment_id,
                "total_amount" => $totalReturnAmount,
                "updated_by" => $request->updated_by,
            ]);

            DB::commit();

            return new PurchaseReturnResource(
                $purchaseReturn->fresh([
                    "supplier",
                    "status",
                    "warehouse",
                    "paymentMethod",
                    "details.product",
                    "createdBy",
                    "updatedBy",
                ])
            );
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                "error" => "Failed to update purchase return",
                "details" => $e->getMessage(),
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
            // Lock Purchase Return with details
            $purchaseReturn = PurchaseReturn::with('details')->lockForUpdate()->findOrFail($id);

            // Prevent double void
            if ($purchaseReturn->status_id == 8) {
                return response()->json([
                    'error' => 'Purchase return already voided',
                ], 422);
            }

            // Rollback stock for each return detail (same inventory as return)
            foreach ($purchaseReturn->details as $detail) {
                $inventory = Inventory::lockForUpdate()->find($detail->inventory_id);

                if (!$inventory) {
                    throw new \Exception("Inventory not found for rollback");
                }

                $inventory->qty += $detail->quantity;
                $inventory->updated_by = $request->void_by;
                $inventory->save();

                StockTransaction::create([
                    'inventory_id' => $inventory->id,
                    'reference_id' => $purchaseReturn->id,
                    'reference_type' => 'purchase_return_void',
                    'reference_date' => $purchaseReturn->return_date,
                    'quantity_change' => $detail->quantity,
                    'type' => 'in',
                    'created_by' => $request->void_by,
                ]);
            }

            // Mark Purchase Return as VOID
            $purchaseReturn->update([
                'status_id' => 8, // VOID
                'void_by' => $request->void_by,
                'void_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Purchase return voided successfully',
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to void purchase return',
                'details' => $e->getMessage(),
            ], 500);
        }
    }
}
