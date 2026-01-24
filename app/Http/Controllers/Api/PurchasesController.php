<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PurchaseResource;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function Symfony\Component\Clock\now;

class PurchasesController extends Controller
{
    public function index(Request $request)
    {
        $query = Purchase::with([
            'supplier',
            'status',
            'warehouse',
            'paymentMethod',
            'details.product',
            'createdBy',
            'updatedBy'
        ]);

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('purchase_date', [$request->start_date, $request->end_date]);
        } elseif ($request->filled('start_date')) {
            $query->whereDate('purchase_date', '>=', $request->start_date);
        } elseif ($request->filled('end_date')) {
            $query->whereDate('purchase_date', '<=', $request->end_date);
        }

        return PurchaseResource::collection($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'payment_id' => 'required|exists:payment_methods,id',
            'paid_amount' => 'nullable|numeric|min:0',
            'status_id' => 'required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'purchase_date' => 'nullable|date',
            'warehouse_id' => 'required|exists:warehouses,id',
            'created_by' => 'required|exists:users,id',

            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.expired_date' => 'nullable|date',
        ]);

        Log::info("request :", $request->all());
        DB::beginTransaction();
        try {
            // Calculate total
            $totalAmount = 0;
            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);

                // Determine purchase price to use
                $incomingPrice = $item['purchase_price'] ?? null;

                if ($incomingPrice && $incomingPrice > 0 && $incomingPrice != $product->purchase_price) {
                    // Update product purchase price and store old_purchase_price
                    $product->old_purchase_price = $product->purchase_price;
                    $product->purchase_price = $incomingPrice;
                    $product->save();
                }

                // Use updated or current price for total
                $price = ($incomingPrice && $incomingPrice > 0) ? $incomingPrice : 0;

                $totalAmount += $price * $item['quantity'];
            }


            // Create Purchase
            $purchase = Purchase::create([
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'total_amount' => $totalAmount,
                'payment_id' => $request->payment_id,
                'status_id' => $request->status_id,
                'remark' => $request->remark,
                'purchase_date' => $request->purchase_date ?? now(),
                'created_by' => $request->created_by,
                'updated_by' => $request->created_by,
            ]);

            // Stock IN + details
            // foreach ($request->products as $item) {

            //     $product = Product::findOrFail($item['product_id']);
            //     $price = $product->purchase_price;
            
            //     $inventory = Inventory::firstOrCreate(
            //         [
            //             'product_id' => $item['product_id'],
            //             'warehouse_id' => $request->warehouse_id,
            //             'expired_date' => $item['expired_date'] ?? null,
            //         ],
            //         [
            //             'qty' => 0,
            //             'created_by' => $request->created_by,
            //             'updated_by' => $request->created_by,
            //         ]
            //     );
            
            //     $inventory->qty += $item['quantity'];
            //     $inventory->updated_by = $request->created_by;
            //     $inventory->save();
            
            //     PurchaseDetail::create([
            //         'purchase_id' => $purchase->id,
            //         'inventory_id' => $inventory->id,
            //         'product_id' => $item['product_id'],
            //         'quantity' => $item['quantity'],
            //         'price' => $price,
            //         'total' => $price * $item['quantity'],
            //     ]);
            
            //     StockTransaction::create([
            //         'inventory_id'    => $inventory->id,
            //         'reference_id'    => $purchase->id,
            //         'reference_type'  => 'purchase',
            //         'quantity_change' => $item['quantity'],
            //         'type'            => 'in',
            //         'created_by'      => $request->created_by,
            //         'updated_by'      => $request->created_by,
            //     ]);
            // }

            foreach ($request->products as $item) {

                $product = Product::findOrFail($item['product_id']);
                $price = $product->purchase_price;

                $remainingQty = $item['quantity'];

                $expiredDate = $item['expired_date'] ?? null;

                $negativeInventories = Inventory::where('product_id', $item['product_id'])
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
                        'reference_id'    => $purchase->id ?? null,
                        'reference_type'  => 'purchase',
                        'reference_date' => $request->purchase_date ?? now(),
                        'quantity_change' => $offsetQty,
                        'type'            => 'in',
                        'created_by'      => $request->created_by
                    ]);

                    $remainingQty -= $offsetQty;
                }

                if ($remainingQty > 0) {
                    
                    $existingInventory = Inventory::where('product_id', $item['product_id'])
                        ->where('warehouse_id', $request->warehouse_id)
                        ->where('expired_date', $expiredDate)
                        ->first();

                    if ($existingInventory) {
                        $existingInventory->qty += $remainingQty;
                        $existingInventory->updated_by = $request->created_by;
                        $existingInventory->save();

                        StockTransaction::create([
                            'inventory_id'    => $existingInventory->id,
                            'reference_id'    => $purchase->id ?? null,
                            'reference_type'  => 'purchase',
                            'reference_date' => $request->purchase_date ?? now(),
                            'quantity_change' => $remainingQty,
                            'type'            => 'in',
                            'created_by'      => $request->created_by
                        ]);
                    } else {
                        $inventory = Inventory::create([
                            'product_id'   => $item['product_id'],
                            'warehouse_id' => $request->warehouse_id,
                            'expired_date'  => $item['expired_date'],
                            'qty'          => $remainingQty,
                            'created_by'   => $request->created_by,
                            'updated_by' => $request->updated_by ?? $request->created_by
                        ]);

                        StockTransaction::create([
                            'inventory_id'    => $inventory->id,
                            'reference_id'    => $purchase->id ?? null,
                            'reference_type'  => 'purchase',
                            'reference_date' => $request->purchase_date ?? now(),
                            'quantity_change' => $remainingQty,
                            'type'            => 'in',
                            'created_by'      => $request->created_by
                        ]);
                    }
                }
            
                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'inventory_id' => $existingInventory -> id ?? $inventory->id ?? $negInv->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $price,
                    'total' => $price * $item['quantity'],
                ]);

            }

            DB::commit();

            return new PurchaseResource(
                $purchase->fresh([
                    'supplier',
                    'warehouse',
                    'status',
                    'paymentMethod',
                    'details.product',
                    'createdBy',
                    'updatedBy'
                ])
            );

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to create purchase',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        $purchase = Purchase::with([
            'supplier',
            'status',
            'paymentMethod',
            'details.product',
            'createdBy',
            'updatedBy'
        ])->findOrFail($id);

        return new PurchaseResource($purchase);
    }

    // public function update(Request $request, string $id)
    // {
    //     $request->validate([
    //         'payment_id' => 'sometimes|required|exists:payment_methods,id',
    //         'status_id' => 'sometimes|required|exists:statuses,id',
    //         'remark' => 'nullable|string|max:1000',
    //         'purchase_date' => 'sometimes|date',
    //         'updated_by' => 'required|exists:users,id'
    //     ]);

    //     $purchase = Purchase::findOrFail($id);

    //     DB::beginTransaction();
    //     try {

    //         $purchase->update([
    //             'payment_id'    => $request->payment_id,
    //             'status_id'     => $request->status_id,
    //             'remark'        => $request->remark,
    //             'purchase_date' => $request->purchase_date,
    //             'updated_by'    => $request->updated_by,
    //         ]);

    //         DB::commit();

    //         return new PurchaseResource(
    //             $purchase->fresh([
    //                 'supplier',
    //                 'warehouse',
    //                 'status',
    //                 'paymentMethod',
    //                 'details.product',
    //                 'createdBy',
    //                 'updatedBy'
    //             ])
    //         );

    //     } catch (\Exception $e) {
    //         DB::rollBack();

    //         return response()->json([
    //             'error' => 'Failed to update purchase',
    //             'details' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'payment_id' => 'sometimes|required|exists:payment_methods,id',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'purchase_date' => 'sometimes|date',
            'updated_by' => 'required|exists:users,id',
            'products' => 'sometimes|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
            'products.*.purchase_price' => 'nullable|numeric|min:0',
            'products.*.expired_date' => 'nullable|date',
        ]);

        $purchase = Purchase::findOrFail($id);

        DB::beginTransaction();
        try {
            $totalAmount = 0;
            foreach ($purchase->details as $detail) {
                $inventory = Inventory::lockForUpdate()->find($detail->inventory_id);

                if (!$inventory) {
                    throw new \Exception("Inventory not found for rollback");
                }

                $inventory->qty -= $detail->quantity;
                $inventory->updated_by = $request->updated_by;
                $inventory->save();

                StockTransaction::create([
                    "inventory_id" => $inventory->id,
                    "reference_id" => $purchase->id,
                    "reference_type" => "purchase_update",
                    'reference_date' => $request->purchase_date ?? $purchase->purchase_date,
                    "quantity_change" => $detail->quantity,
                    "type" => "out",
                    "created_by" => $request->updated_by,
                ]);
            }

            PurchaseDetail::where("purchase_id", $purchase->id)->delete();

            // Update inventory and optionally update purchase_price
            if ($request->has('products')) {
                foreach ($request->products as $item) {
                    $product = Product::findOrFail($item['product_id']);

                    // Update purchase_price if >0
                    if (!empty($item['purchase_price']) && $item['purchase_price'] > 0 && $item['purchase_price'] != $product->purchase_price) {
                        $product->old_purchase_price = $product->purchase_price;
                        $product->purchase_price = $item['purchase_price'];
                        $product->save();
                    }

                    $inventory = Inventory::lockForUpdate()->find($item['inventory_id']);

                    // Add quantity to inventory
                    $inventory->qty += $item['quantity'];
                    $inventory->expired_date = $item['expired_date'];
                    $inventory->updated_by = $request->updated_by;
                    $inventory->save();

                    PurchaseDetail::create([
                        'purchase_id' => $purchase->id,
                        'inventory_id' => $inventory->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $item['quantity'],
                        'price' => $item['purchase_price'],
                        'total' => $item['purchase_price'] * $item['quantity'],
                    ]);

                    // Record stock transaction
                    StockTransaction::create([
                        'inventory_id'    => $inventory->id,
                        'reference_id'    => $purchase->id,
                        'reference_type'  => 'purchase_update',
                        'reference_date' => $request->purchase_date ?? $purchase->purchase_date,
                        'quantity_change' => $item['quantity'],
                        'type'            => 'in',
                        'created_by'      => $request->updated_by,
                        'updated_by'      => $request->updated_by,
                    ]);
                    $totalAmount += $item['purchase_price'] * $item['quantity'];
                }
            }

            // Update purchase header
            $purchase->update([
                'payment_id'    => $request->payment_id ?? $purchase->payment_id,
                'status_id'     => $request->status_id ?? $purchase->status_id,
                'remark'        => $request->remark ?? $purchase->remark,
                'total_amount' => $totalAmount,
                'purchase_date' => $request->purchase_date ?? $purchase->purchase_date,
                'updated_by'    => $request->updated_by,
            ]);

            DB::commit();

            return new PurchaseResource(
                $purchase->fresh([
                    'supplier',
                    'warehouse',
                    'status',
                    'paymentMethod',
                    'details.product',
                    'createdBy',
                    'updatedBy'
                ])
            );

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to update purchase',
                'details' => $e->getMessage()
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
            $purchase = Purchase::with('details')->findOrFail($id);
            $voidStatus = \App\Models\Status::where('name', 'void')->first();

            // Void purchase
            $purchase->update([
                'status_id' => $voidStatus->id,
                'void_at' => now(),
                'void_by' => $request->void_by,
            ]);

            // Rollback stock
            foreach ($purchase->details as $detail) {

                $inventory = Inventory::find($detail->inventory_id);

                $hasOutTransaction = StockTransaction::where('inventory_id', $inventory->id)->where('reference_type', 'sale')->exists();

                if ($hasOutTransaction) {
                    return response()->json([
                        'error' => 'This purchase was already used. Quantity cannot be directly updated. Please use stock adjustment.'
                    ], 422);
                }

                if ($inventory) {
                    $inventory->qty -= $detail->quantity;
                    $inventory->save();
                }

                StockTransaction::create([
                    'inventory_id'    => $inventory->id ?? null,
                    'reference_id'    => $purchase->id,
                    'reference_type'  => 'purchase_void',
                    'reference_date' => $purchase->purchase_date,
                    'quantity_change' => $detail->quantity,
                    'type'            => 'out',
                    'created_by'      => $request->void_by,
                    'updated_by'      => $request->void_by,
                ]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Purchase voided successfully.'
            ]);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error' => 'Failed to void purchase',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}
