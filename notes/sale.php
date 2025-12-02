<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\StockTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SaleController extends Controller
{

    public function index(Request $request)
    {
        $query = Sale::with(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy']);

        // Filter by customer_id (instead of status_id)
        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        $sales = $query->get();
        return SaleResource::collection($sales);
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
            // Calculate total
            $totalAmount = 0;
            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);
                $totalAmount += $product->price * $item['quantity'];
            }

            // Create Sale
            $sale = Sale::create([
                'customer_id' => $request->customer_id,
                'total_amount' => $totalAmount,
                'paid_amount' => $request->paid_amount ?? $totalAmount,
                'due_amount' => $totalAmount - ($request->paid_amount ?? $totalAmount),
                'payment_id' => $request->payment_id,
                'status_id' => $request->status_id,
                'remark' => $request->remark ?? null,
                'sale_date' => $request->sale_date ?? now(),
                'created_by' => $request->created_by,
                'updated_by' => $request->updated_by ?? $request->created_by,
            ]);

            // Sale Details & Inventory
            foreach ($request->products as $item) {
                $product = Product::findOrFail($item['product_id']);

                // Create SaleDetail
                SaleDetail::create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'quantity' => $item['quantity'],
                    'price' => $product->price,
                    'total' => $product->price * $item['quantity'],
                ]);

                // Reduce inventory
                $inventory = Inventory::where('product_id', $product->id)
                    ->where('warehouse_id', $request->warehouse_id)
                    ->firstOrFail();

                if ($inventory->qty < $item['quantity']) {
                    throw new \Exception("Not enough stock for product: {$product->name}");
                }

                $inventory->decrement('qty', $item['quantity']);

                // Stock Transaction
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
        $sale = Sale::with(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy'])
            ->findOrFail($id);
        return new SaleResource($sale);
    }

    public function update(Request $request, string $id)
    {
        $sale = Sale::findOrFail($id);

        $request->validate([
            'payment_id' => 'sometimes|required|exists:payment_methods,id',
            'paid_amount' => 'sometimes|required|numeric|min:0',
            'status_id' => 'sometimes|required|exists:statuses,id',
            'remark' => 'nullable|string|max:1000',
            'sale_date' => 'sometimes|date',
            'updated_by' => 'nullable|exists:users,id',
        ]);

        $sale->update($request->only([
            'payment_id',
            'paid_amount',
            'status_id',
            'sale_date',
            'updated_by'
        ]));

        return new SaleResource($sale->fresh(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy']));
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
