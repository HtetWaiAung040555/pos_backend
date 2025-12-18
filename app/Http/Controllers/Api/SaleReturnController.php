<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleReturnResource;
use App\Models\CustomerTransaction;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnDetail;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDO;

class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        $query = SaleReturn::with([
            "customer",
            "status",
            "warehouse",
            "paymentMethod",
            "details.product",
            "createdBy",
            "updatedBy",
        ]);

        if ($request->filled("customer_id")) {
            $query->where("customer_id", $request->customer_id);
        }

        if ($request->filled("status_id")) {
            $query->where("status_id", $request->status_id);
        }

        if ($request->filled("warehouse_id")) {
            $query->where("warehouse_id", $request->warehouse_id);
        }

        if ($request->filled("start_date") && $request->filled("end_date")) {
            $query->whereBetween("sale_date", [
                $request->start_date,
                $request->end_date,
            ]);
        } elseif ($request->filled("start_date")) {
            $query->whereDate("sale_date", ">=", $request->start_date);
        } elseif ($request->filled("end_date")) {
            $query->whereDate("sale_date", "<=", $request->end_date);
        }

        return SaleReturnResource::collection($query->get());
    }

    public function store(Request $request)
    {
        Log::info("Before: ", $request->all());
        $request->validate([
            "sale_id" => "required|exists:sales,id",
            "warehouse_id" => "required|exists:warehouses,id",
            "customer_id" => "required|exists:customers,id",
            "payment_id" => "required|exists:payment_methods,id",
            "remark" => "nullable|string|max:1000",
            "created_by" => "required|exists:users,id",
            "updated_by" => "nullable|exists:users,id",
            "return_date" => "nullable|date",
            "products" => "required|array|min:1",
            "products.*.sale_detail_id" => "required|exists:sale_details,id",
            "products.*.quantity" => "required|integer|min:1",
        ]);

        // Log::info("Data", $request->all());

        DB::beginTransaction();

        try {
            // 1️⃣ Lock sale row (important for concurrency)
            $sale = Sale::with("details")
                ->lockForUpdate()
                ->findOrFail($request->sale_id);

            // 2️⃣ Only confirmed sales can be returned
            if ($sale->status_id != 7) {
                throw ValidationException::withMessages([
                    "sale" => "Only confirmed sales can be returned",
                ]);
            }

            // 3️⃣ Create Sale Return (header)
            $saleReturn = SaleReturn::create([
                "sale_id" => $sale->id,
                "warehouse_id" => $request->warehouse_id,
                "customer_id" => $request->customer_id,
                "payment_id" => $request->payment_id,
                "status_id" => $request->status_id,
                "remark" => $request->remark,
                "return_date" => $request->return_date ?? now(),
                "total_amount" => 0,
                "created_by" => $request->created_by,
                "updated_by" => $request->updated_by ?? $request->created_by,
            ]);

            $totalReturnAmount = 0;

            // 4️⃣ Loop return products
            foreach ($request->products as $item) {
                // Get original sale detail
                $saleDetail = $sale->details
                    ->where("id", $item["sale_detail_id"])
                    ->firstOrFail();

                // Already returned qty
                $alreadyReturned = SaleReturnDetail::where(
                    "sale_detail_id",
                    $saleDetail->id,
                )->sum("quantity");

                $availableQty = $saleDetail->quantity - $alreadyReturned;

                // Validate return qty
                if ($item["quantity"] > $availableQty) {
                    return response()->json(
                        [
                            "error" => "Return quantity exceeds sold quantity",
                        ],
                        422,
                    );
                }

                // Calculate line total
                $lineTotal = $item["quantity"] * $saleDetail->price;

                Log::info($saleReturn);

                // 5️⃣ Create Sale Return Detail
                SaleReturnDetail::create([
                    "sale_return_id" => $saleReturn->id,
                    "sale_detail_id" => $saleDetail->id,
                    "product_id" => $saleDetail->product_id,
                    "quantity" => $item["quantity"],
                    "price" => $saleDetail->price,
                    "total" => $lineTotal,
                ]);

                // 6️⃣ FIFO stock IN (negative first)
                $remainingQty = $item["quantity"];

                $inventories = Inventory::where(
                    "product_id",
                    $saleDetail->product_id,
                )
                    ->where("warehouse_id", $sale->warehouse_id)
                    ->where("qty", "<", 0)
                    ->orderBy("created_at")
                    ->lockForUpdate()
                    ->get();

                foreach ($inventories as $inventory) {
                    if ($remainingQty <= 0) {
                        break;
                    }

                    $offsetQty = min(abs($inventory->qty), $remainingQty);

                    $inventory->qty += $offsetQty;
                    $inventory->save();

                    StockTransaction::create([
                        "inventory_id" => $inventory->id,
                        "reference_id" => $saleReturn->id,
                        "reference_type" => "sale_return",
                        "quantity_change" => $offsetQty,
                        "type" => "in",
                        "created_by" => $request->created_by,
                    ]);

                    $remainingQty -= $offsetQty;
                }

                if ($remainingQty > 0) {
                    $inventory = Inventory::create([
                        "product_id" => $saleDetail->product_id,
                        "warehouse_id" => $sale->warehouse_id,
                        "qty" => $remainingQty,
                        "created_by" => $request->created_by,
                        "updated_by" => $request->created_by,
                    ]);

                    StockTransaction::create([
                        "inventory_id" => $inventory->id,
                        "reference_id" => $saleReturn->id,
                        "reference_type" => "sale_return",
                        "quantity_change" => $remainingQty,
                        "type" => "in",
                        "created_by" => $request->created_by,
                    ]);
                }

                $totalReturnAmount += $lineTotal;
            }

            // 7️⃣ Update total amount
            $saleReturn->update([
                "total_amount" => $totalReturnAmount,
            ]);

            DB::commit();

            // 8️⃣ Return resource (same style as SaleController)
            return new SaleReturnResource(
                $saleReturn->fresh([
                    "customer",
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
                    "error" => "Failed to create sale return",
                    "details" => $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function show(string $id)
    {
        $sale_return = SaleReturn::with([
            "customer",
            "status",
            "paymentMethod",
            "details.product",
            "createdBy",
            "updatedBy",
        ])->findOrFail($id);
        return new SaleReturnResource($sale_return);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            "remark" => "nullable|string|max:1000",
            "updated_by" => "required|exists:users,id",
            "return_date" => "nullable|date",
            "products" => "required|array|min:1",
            "products.*.sale_detail_id" => "required|exists:sale_details,id",
            "products.*.quantity" => "required|integer|min:1",
        ]);

        Log::info("Request:", $request->all());

        DB::beginTransaction();

        try {
            // 1️⃣ Lock Sale Return
            $saleReturn = SaleReturn::with(["details", "details.saleDetail"])
                ->lockForUpdate()
                ->findOrFail($id);

            Log::info("sales return:", $saleReturn->toArray());

            $sale = Sale::where("id", $saleReturn->sale_id)->firstOrFail();

            // 3️⃣ ROLLBACK previous stock-in
            foreach ($saleReturn->details as $detail) {
                $remainingQty = $detail->quantity;

                // Deduct FIFO (positive inventory first)
                $inventories = Inventory::where(
                    "product_id",
                    $detail->product_id,
                )
                    ->where("warehouse_id", $saleReturn->warehouse_id)
                    ->where("qty", ">", 0)
                    ->orderByRaw("expired_date IS NULL")
                    ->orderBy("expired_date")
                    ->orderBy("created_at")
                    ->lockForUpdate()
                    ->get();

                foreach ($inventories as $inventory) {
                    if ($remainingQty <= 0) {
                        break;
                    }

                    $deductQty = min($inventory->qty, $remainingQty);
                    $inventory->qty -= $deductQty;
                    $inventory->updated_by = $request->updated_by;
                    $inventory->save();

                    StockTransaction::create([
                        "inventory_id" => $inventory->id,
                        "reference_id" => $saleReturn->id,
                        "reference_type" => "sale_return_update",
                        "quantity_change" => $deductQty,
                        "type" => "out",
                        "created_by" => $request->updated_by,
                    ]);

                    $remainingQty -= $deductQty;
                }

                // Remaining → negative inventory
                if ($remainingQty > 0) {
                    $negativeInventory = Inventory::firstOrCreate(
                        [
                            "product_id" => $detail->product_id,
                            "warehouse_id" => $saleReturn->warehouse_id,
                            "expired_date" => null,
                        ],
                        [
                            "qty" => 0,
                            "created_by" => $request->updated_by,
                            "updated_by" => $request->updated_by,
                        ],
                    );

                    $negativeInventory->qty -= $remainingQty;
                    $negativeInventory->save();

                    StockTransaction::create([
                        "inventory_id" => $negativeInventory->id,
                        "reference_id" => $saleReturn->id,
                        "reference_type" => "sale_return_update",
                        "quantity_change" => $remainingQty,
                        "type" => "out",
                        "created_by" => $request->updated_by,
                    ]);
                }
            }

            // 4️⃣ Delete old return details
            SaleReturnDetail::where(
                "sale_return_id",
                $saleReturn->id,
            )->delete();

            // 5️⃣ Re-apply return (same logic as store)
            $totalReturnAmount = 0;

            Log::info($sale->toArray());

            foreach ($request->products as $item) {
                $saleDetail = $sale->details
                    ->where("id", $item["sale_detail_id"])
                    ->firstOrFail();

                $alreadyReturned = SaleReturnDetail::where(
                    "sale_detail_id",
                    $saleDetail->id,
                )
                    ->where("sale_return_id", "!=", $saleReturn->id)
                    ->sum("quantity");

                $availableQty = $saleDetail->quantity - $alreadyReturned;

                if ($item["quantity"] > $availableQty) {
                    throw ValidationException::withMessages([
                        "quantity" => "Return quantity exceeds sold quantity",
                    ]);
                }

                $lineTotal = $item["quantity"] * $saleDetail->price;

                SaleReturnDetail::create([
                    "sale_return_id" => $saleReturn->id,
                    "sale_detail_id" => $saleDetail->id,
                    "product_id" => $saleDetail->product_id,
                    "quantity" => $item["quantity"],
                    "price" => $saleDetail->price,
                    "total" => $lineTotal,
                ]);

                // FIFO stock IN (negative first)
                $remainingQty = $item["quantity"];

                $negatives = Inventory::where(
                    "product_id",
                    $saleDetail->product_id,
                )
                    ->where("warehouse_id", $saleReturn->warehouse_id)
                    ->where("qty", "<", 0)
                    ->orderBy("created_at")
                    ->lockForUpdate()
                    ->get();

                foreach ($negatives as $inventory) {
                    if ($remainingQty <= 0) {
                        break;
                    }

                    $offsetQty = min(abs($inventory->qty), $remainingQty);
                    $inventory->qty += $offsetQty;
                    $inventory->save();

                    StockTransaction::create([
                        "inventory_id" => $inventory->id,
                        "reference_id" => $saleReturn->id,
                        "reference_type" => "sale_return",
                        "quantity_change" => $offsetQty,
                        "type" => "in",
                        "created_by" => $request->updated_by,
                    ]);

                    $remainingQty -= $offsetQty;
                }

                if ($remainingQty > 0) {
                    $inventory = Inventory::create([
                        "product_id" => $saleDetail->product_id,
                        "warehouse_id" => $saleReturn->warehouse_id,
                        "qty" => $remainingQty,
                        "created_by" => $request->updated_by,
                        "updated_by" => $request->updated_by,
                    ]);

                    StockTransaction::create([
                        "inventory_id" => $inventory->id,
                        "reference_id" => $saleReturn->id,
                        "reference_type" => "sale_return",
                        "quantity_change" => $remainingQty,
                        "type" => "in",
                        "created_by" => $request->updated_by,
                    ]);
                }

                $totalReturnAmount += $lineTotal;
            }

            // 6️⃣ Update header
            $saleReturn->update([
                "remark" => $request->remark,
                "return_date" =>
                    $request->return_date ?? $saleReturn->return_date,
                "total_amount" => $totalReturnAmount,
                "updated_by" => $request->updated_by,
            ]);

            DB::commit();

            return new SaleReturnResource(
                $saleReturn->fresh([
                    "customer",
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
                    "error" => "Failed to update sale return",
                    "details" => $e->getMessage(),
                ],
                500,
            );
        }
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();

        try {
            // 1️⃣ Lock Sale Return with details
            $saleReturn = SaleReturn::with(["details"])
                ->lockForUpdate()
                ->findOrFail($id);

            // 2️⃣ Prevent double void
            if ($saleReturn->status_id == 8) {
                return response()->json(
                    [
                        "error" => "Sale return already voided",
                    ],
                    422,
                );
            }

            // 3️⃣ Rollback stock (reverse stock-in)
            foreach ($saleReturn->details as $detail) {
                $remainingQty = $detail->quantity;

                // Deduct FIFO from positive inventory
                $inventories = Inventory::where(
                    "product_id",
                    $detail->product_id,
                )
                    ->where("warehouse_id", $saleReturn->warehouse_id)
                    ->where("qty", ">", 0)
                    ->orderByRaw("expired_date IS NULL")
                    ->orderBy("expired_date")
                    ->orderBy("created_at")
                    ->lockForUpdate()
                    ->get();

                foreach ($inventories as $inventory) {
                    if ($remainingQty <= 0) {
                        break;
                    }

                    $deductQty = min($inventory->qty, $remainingQty);
                    $inventory->qty -= $deductQty;
                    $inventory->updated_by = $request->void_by;
                    $inventory->save();

                    StockTransaction::create([
                        "inventory_id" => $inventory->id,
                        "reference_id" => $saleReturn->id,
                        "reference_type" => "sale_return_void",
                        "quantity_change" => $deductQty,
                        "type" => "out",
                        "created_by" => $request->void_by,
                    ]);

                    $remainingQty -= $deductQty;
                }

                // Remaining → negative inventory
                if ($remainingQty > 0) {
                    $negativeInventory = Inventory::firstOrCreate(
                        [
                            "product_id" => $detail->product_id,
                            "warehouse_id" => $saleReturn->warehouse_id,
                            "expired_date" => null,
                        ],
                        [
                            "qty" => 0,
                            "created_by" => $request->void_by,
                            "updated_by" => $request->void_by,
                        ],
                    );

                    $negativeInventory->qty -= $remainingQty;
                    $negativeInventory->updated_by = $request->void_by;
                    $negativeInventory->save();

                    StockTransaction::create([
                        "inventory_id" => $negativeInventory->id,
                        "reference_id" => $saleReturn->id,
                        "reference_type" => "sale_return_void",
                        "quantity_change" => $remainingQty,
                        "type" => "out",
                        "created_by" => $request->void_by,
                    ]);
                }
            }

            // 4️⃣ Mark Sale Return as VOID
            $saleReturn->update([
                "status_id" => 8, // VOID
                "void_by" => $request->void_by,
                "void_at" => now(),
            ]);

            DB::commit();

            return response()->json(
                [
                    "message" => "Sale return voided successfully",
                ],
                200,
            );
        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json(
                [
                    "error" => "Failed to void sale return",
                    "details" => $e->getMessage(),
                ],
                500,
            );
        }
    }
}
