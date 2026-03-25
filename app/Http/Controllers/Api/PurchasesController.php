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
        $query = PurchasesController::build($request)
            ->select('purchases.*')
            ->with([
                'supplier',
                'status',
                'warehouse',
                'paymentMethod',
                'details.product',
                'createdBy',
                'updatedBy'
            ])
            ->orderByDesc('purchases.purchase_date');

        $perPage = $request->get('per_page', 50);

        $purchases = $query->paginate($perPage);

        return PurchaseResource::collection($purchases);
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

            foreach ($request->products as $item) {

                $product = Product::findOrFail($item['product_id']);
                $price = $item['purchase_price'];

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
                    $negInv->expired_date = $expiredDate; // Update expired date if provided
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

                    PurchaseDetail::create([
                        'purchase_id' => $purchase->id,
                        'inventory_id' => $negInv->id,
                        'product_id' => $item['product_id'],
                        'quantity' => $offsetQty,
                        'price' => $price,
                        'total' => $price * $offsetQty,
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

                        PurchaseDetail::create([
                            'purchase_id' => $purchase->id,
                            'inventory_id' => $existingInventory->id,
                            'product_id' => $item['product_id'],
                            'quantity' => $remainingQty,
                            'price' => $price,
                            'total' => $price * $remainingQty,
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

                        PurchaseDetail::create([
                            'purchase_id' => $purchase->id,
                            'inventory_id' => $inventory->id,
                            'product_id' => $item['product_id'],
                            'quantity' => $remainingQty,
                            'price' => $price,
                            'total' => $price * $remainingQty,
                        ]);

                    }
                }

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

    public function update(Request $request, string $id)
    {
        $request->validate([
            'payment_id' => 'sometimes|required|exists:payment_methods,id',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'purchase_date' => 'sometimes|date',
            'updated_by' => 'required|exists:users,id',
        ]);

        $purchase = Purchase::findOrFail($id);

        DB::beginTransaction();

        try {

            $totalAmount = 0;

            // Update inventory and optionally update purchase_price
            if ($request->products) {

                foreach ($purchase->details as $detail) {

                    $inventory = Inventory::lockForUpdate()->find($detail->inventory_id);

                    if (!$inventory) {
                        throw new \Exception("Inventory not found for rollback");
                    }

                    $inventory->qty -= $detail->quantity;
                    $inventory->updated_by = $request->updated_by;
                    $inventory->save();

                }

                PurchaseDetail::where("purchase_id", $purchase->id)->delete();

                StockTransaction::where('reference_id', $purchase->id)
                    ->delete();

                foreach ($request->products as $item) {

                    $product = Product::findOrFail($item['product_id']);

                    // Update purchase_price if >0
                    if (!empty($item['purchase_price']) && $item['purchase_price'] > 0 && $item['purchase_price'] != $product->purchase_price) {
                        $product->old_purchase_price = $product->purchase_price;
                        $product->purchase_price = $item['purchase_price'];
                        $product->save();
                    }

                    $remainingQty = $item['quantity'];

                    $inventories = Inventory::where('product_id', $item['product_id'])
                    ->where('warehouse_id', $purchase->warehouse_id)
                    ->lockForUpdate()
                    ->get();

                    /* Offset negative inventories first */
                    foreach ($inventories->where('qty', '<', 0)->sortBy('created_at') as $negInv) {

                        if ($remainingQty <= 0) break;

                        $offsetQty = min(abs($negInv->qty), $remainingQty);

                        $negInv->increment('qty', $offsetQty);
                        $negInv->expired_date = $item['expired_date'] ?? null;
                        $negInv->update(['updated_by' => $request->updated_by]);

                        StockTransaction::create([
                            'inventory_id'    => $negInv->id,
                            'reference_id'    => $purchase->id,
                            'reference_type'  => 'purchase',
                            'reference_date'  => $request->purchase_date ?? $purchase->purchase_date,
                            'quantity_change' => $offsetQty,
                            'type'            => 'in',
                            'created_by'      => $request->updated_by,
                        ]);

                        /* Store Purchase Detail */
                        PurchaseDetail::create([
                            'purchase_id' => $purchase->id,
                            'inventory_id' => $negInv->id,
                            'product_id' => $item['product_id'],
                            'quantity' => $offsetQty,
                            'price' => $item['purchase_price'],
                            'total' => $item['purchase_price'] * $offsetQty,
                        ]);

                        $remainingQty -= $offsetQty;
                    }

                    /* Add remaining stock to correct inventory */
                    $inventory = null;

                    if ($remainingQty > 0) {

                        $inventory = $inventories
                            ->where('expired_date', $item['expired_date'] ?? null)
                            ->first();

                        if ($inventory) {

                            $inventory->increment('qty', $remainingQty);
                            $inventory->update(['updated_by' => $request->updated_by]);

                        } else {

                            $inventory = Inventory::create([
                                'product_id'   => $item['product_id'],
                                'warehouse_id' => $purchase->warehouse_id,
                                'expired_date' => $item['expired_date'] ?? null,
                                'qty'          => $remainingQty,
                                'created_by'   => $request->updated_by,
                                'updated_by'   => $request->updated_by,
                            ]);
                        }

                        StockTransaction::create([
                            'inventory_id'    => $inventory->id,
                            'reference_id'    => $purchase->id,
                            'reference_type'  => 'purchase',
                            'reference_date'  => $request->purchase_date ?? $purchase->purchase_date,
                            'quantity_change' => $remainingQty,
                            'type'            => 'in',
                            'created_by'      => $request->updated_by,
                        ]);

                        /* Store Purchase Detail */
                        PurchaseDetail::create([
                            'purchase_id' => $purchase->id,
                            'inventory_id' => $inventory->id,
                            'product_id' => $item['product_id'],
                            'quantity' => $remainingQty,
                            'price' => $item['purchase_price'],
                            'total' => $item['purchase_price'] * $remainingQty,
                        ]);
                    }

                    $totalAmount += $item['purchase_price'] * $item['quantity'];
                }
            } else {
                $totalAmount = $purchase->total_amount;
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

    public static function build(Request $request)
    {
        return Purchase::query()
            ->when($request->filled('purchase_id'), function ($q) use ($request) {
                $q->where('purchases.id', $request->purchase_id);
            })

            ->when($request->filled('supplier_search'), function ($q) use ($request) {

                $keyword = $request->supplier_search;

                $q->join('suppliers', 'purchases.supplier_id', '=', 'suppliers.id')
                  ->where(function ($sub) use ($keyword) {
                      $sub->where('suppliers.id', $keyword)
                          ->orWhere('suppliers.name', 'like', "%{$keyword}%");
                  });
            })

            ->when($request->filled('status_id'), function ($q) use ($request) {
                $q->where('purchases.status_id', $request->status_id);
            })

            ->when($request->filled('payment_id'), function ($q) use ($request) {
                $q->where('purchases.payment_id', $request->payment_id);
            })

            ->when($request->filled('warehouse_id'), function ($q) use ($request) {
                $q->where('purchases.warehouse_id', $request->warehouse_id);
            })

            ->when($request->filled('product_search'), function ($q) use ($request) {

                $keyword = $request->product_search;

                $q->join('purchase_details', 'purchases.id', '=', 'purchase_details.purchase_id')
                  ->join('products', 'purchase_details.product_id', '=', 'products.id')
                  ->where(function ($sub) use ($keyword) {
                      $sub->where('products.barcode', $keyword)
                          ->orWhere('products.name', 'like', "%{$keyword}%");
                  });
            })

            ->when($request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request) {
                $q->whereBetween('purchases.purchase_date', [$request->start_date, $request->end_date]);
            })

            ->when($request->filled('start_date') && !$request->filled('end_date'), function ($q) use ($request) {
                $q->whereDate('purchases.purchase_date', '>=', $request->start_date);
            })

            ->when($request->filled('end_date') && !$request->filled('start_date'), function ($q) use ($request) {
                $q->whereDate('purchases.purchase_date', '<=', $request->end_date);
            })

            ->distinct();
    }

    public function export(Request $request)
    {
        $purchases = PurchasesController::build($request)
            ->select('purchases.*')
            ->with(['supplier','details.product'])
            ->orderByDesc('purchases.purchase_date')
            ->get();

        return PurchaseResource::collection($purchases);
    }

    public function dashboard(Request $request)
    {
        $query = PurchasesController::build($request);

        $stats = (clone $query)
            ->selectRaw("
                COUNT(DISTINCT purchases.id) as total_invoice,
                COALESCE(SUM(purchases.total_amount),0) as total_purchases,
                COALESCE(SUM(CASE WHEN purchases.payment_id = 1 THEN purchases.total_amount END),0) as total_cash,
                COALESCE(SUM(CASE WHEN purchases.payment_id = 4 THEN purchases.total_amount END),0) as total_kpay,
                COALESCE(SUM(CASE WHEN purchases.payment_id = 2 THEN purchases.total_amount END),0) as total_credit
            ")
            ->first();

        return response()->json($stats);
    }
    
}
