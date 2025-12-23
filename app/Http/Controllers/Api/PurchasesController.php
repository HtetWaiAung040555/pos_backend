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

        DB::beginTransaction();
        try {
            // Calculate total
            $totalAmount = 0;
            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);
                $price = $product->purchase_price;

                $totalAmount += $price * $item['quantity'];
            }

            $paidAmount = $request->paid_amount ?? 0;
            $dueAmount  = $totalAmount - $paidAmount;
            if ($dueAmount < 0) $dueAmount = 0;

            // Create Purchase
            $purchase = Purchase::create([
                'warehouse_id' => $request->warehouse_id,
                'supplier_id' => $request->supplier_id,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_id' => $request->payment_id,
                'status_id' => $request->status_id,
                'remark' => $request->remark,
                'purchase_date' => $request->purchase_date ?? now(),
                'created_by' => $request->created_by,
                'updated_by' => $request->created_by,
            ]);

            // Stock IN + details
            foreach ($request->products as $item) {

                $product = Product::findOrFail($item['product_id']);
                $price = $product->purchase_price;
            
                $inventory = Inventory::firstOrCreate(
                    [
                        'product_id' => $item['product_id'],
                        'warehouse_id' => $request->warehouse_id,
                        'expired_date' => $item['expired_date'] ?? null,
                    ],
                    [
                        'qty' => 0,
                        'created_by' => $request->created_by,
                        'updated_by' => $request->created_by,
                    ]
                );
            
                $inventory->qty += $item['quantity'];
                $inventory->updated_by = $request->created_by;
                $inventory->save();
            
                PurchaseDetail::create([
                    'purchase_id' => $purchase->id,
                    'inventory_id' => $inventory->id,
                    'product_id' => $item['product_id'],
                    'quantity' => $item['quantity'],
                    'price' => $price,
                    'total' => $price * $item['quantity'],
                ]);
            
                StockTransaction::create([
                    'inventory_id'    => $inventory->id,
                    'reference_id'    => $purchase->id,
                    'reference_type'  => 'purchase',
                    'quantity_change' => $item['quantity'],
                    'type'            => 'in',
                    'created_by'      => $request->created_by,
                    'updated_by'      => $request->created_by,
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
            // Log::error($e->getMessage());

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
            'paid_amount' => 'sometimes|required|numeric|min:0',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'purchase_date' => 'sometimes|date',
            'updated_by' => 'required|exists:users,id'
        ]);

        $purchase = Purchase::findOrFail($id);

        DB::beginTransaction();
        try {

            $purchase->update([
                'payment_id'    => $request->payment_id,
                'paid_amount'   => $request->paid_amount,
                'status_id'     => $request->status_id,
                'remark'        => $request->remark,
                'purchase_date' => $request->purchase_date,
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
                if ($inventory) {
                    $inventory->qty -= $detail->quantity;
                    $inventory->save();
                }

                StockTransaction::create([
                    'inventory_id'    => $inventory->id ?? null,
                    'reference_id'    => $purchase->id,
                    'reference_type'  => 'purchase_void',
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
