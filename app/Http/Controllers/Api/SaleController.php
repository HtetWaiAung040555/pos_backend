<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\StockTransaction;
use App\Models\CustomerTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy']);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('sale_date', [$request->start_date, $request->end_date]);
        } elseif ($request->filled('start_date')) {
            $query->whereDate('sale_date', '>=', $request->start_date);
        } elseif ($request->filled('end_date')) {
            $query->whereDate('sale_date', '<=', $request->end_date);
        }

        return SaleResource::collection($query->get());
    }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'payment_id' => 'required|exists:payment_methods,id',
            'paid_amount' => 'nullable|numeric|min:0',
            'status_id' => 'required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'created_by' => 'required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id',
            'sale_date' => 'nullable|date',
            'warehouse_id' => 'required|exists:inventories,warehouse_id',
            'products' => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity' => 'required|integer|min:1',
        ]);

        DB::beginTransaction();
        try {
            // 1. Calculate total amount
            $totalAmount = 0;
            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);
                $totalAmount += $product->price * $item['quantity'];
            }

            // 2. Calculate change (due_amount)
            $paidAmount = $request->paid_amount;
            $dueAmount = $paidAmount - $totalAmount; // change amount

            if ($dueAmount < 0) {
                $dueAmount = 0; // avoid negative change
            }

            // 3. Create Sale
            $sale = Sale::create([
                'customer_id' => $request->customer_id,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_id' => $request->payment_id,
                'status_id' => $request->status_id,
                'remark' => $request->remark ?? null,
                'sale_date' => $request->sale_date ?? now(),
                'created_by' => $request->created_by,
                'updated_by' => $request->updated_by ?? $request->created_by,
            ]);

            // 4. Create Sale Details and Stock Transactions
            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);

                if (!empty($item['promotion_id'])) {

                    $finalPrice = $product->price - $item->discount_amount;
            
                } else {
                    $finalPrice = $product->price;
                }

                SaleDetail::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                    'discount_amount' => $item->discount_amount,
                    'discount_price' => $item->discount_price,
                    'promotion_id' => $item->promotion_id,
                    'total' => $finalPrice * $item['quantity'],
                ]);

                $inventory = Inventory::firstOrCreate(
                    ['product_id' => $product->id, 'warehouse_id' => $request->warehouse_id],
                    [
                        'qty' => 0,
                        'name' => $product->name,
                        'created_by' => $request->created_by,
                        'updated_by' => $request->updated_by ?? $request->created_by, 
                    ]
                );

                $inventory->decrement('qty', $item['quantity']);

                StockTransaction::create([
                    'inventory_id' => $inventory->id,
                    'reference_id' => $sale->id,
                    'reference_type' => 'sale',
                    'quantity_change' => -$item['quantity'],
                    'type' => 'out',
                    'created_by' => $request->created_by,
                    'updated_by' => $request->updated_by ?? $request->created_by,
                ]);
            }

            DB::commit();

            return new SaleResource($sale->fresh(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy']));

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to create sale', 'details' => $e->getMessage()], 500);
        }
    }

    public function show(string $id)
    {
        $sale = Sale::with(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new SaleResource($sale);
    }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'payment_id' => 'sometimes|required|exists:payment_methods,id',
            'paid_amount' => 'sometimes|required|numeric|min:0',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'sale_date' => 'sometimes|date',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        $sale = Sale::with('status')->findOrFail($id); // Load sale with status relation

        DB::beginTransaction();
        try {

            // 1. Update sale fields
            $sale->update([
                'payment_id' => $request->payment_id,
                'paid_amount' => $request->paid_amount,
                'status_id' => $request->status_id,
                'remark' => $request->remark,
                'sale_date' => $request->sale_date,
                'updated_by' => $request->updated_by,
            ]);


            // 2. Create CustomerTransaction only if status changed
            CustomerTransaction::create([
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'type' => 'sale',
                'amount' => -($sale->total_amount),
                'payment_id' => $sale->payment_id,
                'status_id' => 7,
                'pay_date' => $sale->sale_date,
                'created_by' => $sale->updated_by,
                'updated_by' => $sale->updated_by
            ]);
            

            // 3. Update customer balances
            $customer = $sale->customer;
            if ($sale->payment_id == 2 || $sale->payment_id == 3) {
                $customer->balance -= $sale->total_amount;
            }
            
            $customer->save();

            DB::commit();

            // 4. Return updated sale resource with relationships
            return new SaleResource(
                $sale->fresh(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy'])
            );

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to update sale',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();

        try {
            // Load sale + details
            $sale = Sale::with('details')->findOrFail($id);

            // Find void status ID
            $voidStatus = \App\Models\Status::where('name', 'void')->first();
            // if (!$voidStatus) {
            //     return response()->json(['error' => 'Void status not found'], 404);
            // }

            // 1. Update sale status
            $sale->status_id = $voidStatus->id;
            $sale->void_at = now();
            $sale->void_by = $request->void_by;
            $sale->save();

            // 2. Restore stock to inventory
            foreach ($sale->details as $detail) {

                $inventory = Inventory::where('product_id', $detail->product_id)
                                    ->where('warehouse_id', $sale->warehouse_id)
                                    ->first();

                if ($inventory) {
                    $inventory->increment('qty', $detail->quantity);
                }

                // 3. Insert stock transaction
                StockTransaction::create([
                    'inventory_id' => $inventory->id ?? null,
                    'reference_id' => $sale->id,
                    'reference_type' => 'sale_void',
                    'quantity_change' => $detail->quantity,
                    'type' => 'in',
                    'created_by' => $sale->void_by,
                    'updated_by' => $sale->void_by,
                ]);
            }

            // 4. Remove customer transactions related to this sale
            CustomerTransaction::where('sale_id', $sale->id)->delete();

            DB::commit();

            return response()->json([
                'message' => 'Sale voided successfully, stock returned, void info saved.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'error' => 'Failed to void sale',
                'details' => $e->getMessage()
            ], 500);
        }
    }
}