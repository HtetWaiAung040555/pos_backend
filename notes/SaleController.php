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
            $totalAmount = 0;
            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);
                $totalAmount += $product->price * $item['quantity'];
            }

            $paidAmount = $request->paid_amount ?? $totalAmount;
            $dueAmount = $totalAmount - $paidAmount;

            // Create Sale
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

            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);

                SaleDetail::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                    'total' => $product->price * $item['quantity'],
                ]);

                // Safe Inventory lookup or create if missing
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

            // Create Customer Transaction
            CustomerTransaction::create([
                'customer_id' => $sale->customer_id,
                'sale_id' => $sale->id,
                'type' => 'sale',
                'amount' => $paidAmount,
                'remark' => $sale->remark,
                'created_by' => $sale->created_by,
                'updated_by' => $sale->updated_by,
            ]);

            // Update Customer balances
            $customer = $sale->customer;
            $customer->payable += $dueAmount;
            $customer->paid_amount += $paidAmount;
            $customer->total = $customer->paid_amount - $customer->payable; // safer accounting
            $customer->save();

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

        $sale = Sale::findOrFail($id);
        $oldPaidAmount = $sale->paid_amount;
        $oldDueAmount = $sale->due_amount;

        DB::beginTransaction();
        try {
            $sale->update($request->only(['payment_id', 'paid_amount', 'status_id', 'remark', 'sale_date', 'updated_by']));

            if ($request->filled('paid_amount') && $request->paid_amount != $oldPaidAmount) {
                $difference = $sale->paid_amount - $oldPaidAmount;

                // Create a new transaction for the difference
                CustomerTransaction::create([
                    'customer_id' => $sale->customer_id,
                    'sale_id' => $sale->id,
                    'type' => 'payment',
                    'amount' => $difference,
                    'remark' => 'Updated payment',
                    'created_by' => $sale->updated_by,
                    'updated_by' => $sale->updated_by,
                ]);

                $customer = $sale->customer;
                $customer->payable -= $difference;
                $customer->paid_amount += $difference;
                $customer->total = $customer->paid_amount - $customer->payable;
                $customer->save();
            }

            DB::commit();
            return new SaleResource($sale->fresh(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy']));
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['error' => 'Failed to update sale', 'details' => $e->getMessage()], 500);
        }
    }

    public function destroy(string $id)
    {
        try {
            Sale::findOrFail($id)->delete();
            return response()->json(['message' => 'Sale deleted successfully'], 200);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Sale cannot be deleted', 'details' => $e->getMessage()], 400);
        }
    }
}
