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

use function Symfony\Component\Clock\now;

class SaleController extends Controller
{
    public function index(Request $request)
    {
        $query = Sale::with(['customer', 'status', 'warehouse', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy']);

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->customer_id);
        }

        if ($request->filled('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
        }

        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('sale_date', [$request->start_date, $request->end_date]);
        } elseif ($request->filled('start_date')) {
            $query->whereDate('sale_date', '>=', $request->start_date);
        } elseif ($request->filled('end_date')) {
            $query->whereDate('sale_date', '<=', $request->end_date);
        }

        return SaleResource::collection($query->OrderBy('sale_date')->get());
    }

    // public function store(Request $request)
    // {

    //     $request->validate([
    //         'customer_id' => 'required|exists:customers,id',
    //         'payment_id' => 'required|exists:payment_methods,id',
    //         'paid_amount' => 'nullable|numeric|min:0',
    //         'status_id' => 'required|exists:statuses,id',
    //         'remark' => 'nullable|string|max:1000',
    //         'created_by' => 'required|exists:users,id',
    //         'updated_by' => 'nullable|exists:users,id',
    //         'sale_date' => 'nullable|date',
    //         'warehouse_id' => 'required|exists:warehouses,id',
    //         'products' => 'required|array|min:1',
    //         'products.*.product_id' => 'required|exists:products,id',
    //         'products.*.quantity' => 'required|integer|min:1'
    //     ]);

    //     DB::beginTransaction();

    //     try {
    //         // 1. Calculate total amount
    //         $totalAmount = 0;
    //         foreach ($request->products as $item) {
    //             $product = Product::findOrFail($item['product_id']);
    //             $totalAmount += ($item['promotion_id'] ? $item['discount_price'] : $item['price']) * $item['quantity'];
    //         }

    //         // 2. Calculate change (due_amount)
    //         $paidAmount = $request->paid_amount ?? 0;
    //         $dueAmount = $paidAmount - $totalAmount; // change amount

    //         if ($dueAmount < 0) $dueAmount = 0; // avoid negative change

    //         // 3. Create Sale
    //         $sale = Sale::create([
    //             'id' => $request->id,
    //             'warehouse_id' => $request->warehouse_id,
    //             'customer_id' => $request->customer_id,
    //             'total_amount' => $totalAmount,
    //             'paid_amount' => $paidAmount,
    //             'due_amount' => $dueAmount,
    //             'payment_id' => $request->payment_id,
    //             'status_id' => $request->status_id,
    //             'remark' => $request->remark ?? null,
    //             'sale_date' => $request->sale_date ?? now(),
    //             'created_by' => $request->created_by,
    //             'updated_by' => $request->updated_by ?? $request->created_by,
    //             'is_synced' => true,
    //             'sync_at' => now(),
    //         ]);

    //         // 4. Create Sale Details and Stock Transactions
    //         foreach ($request->products as $item) {
    //             $product = Product::findOrFail($item['product_id']);
    //             $finalPrice = $item['price'];

    //             if (!empty($item['promotion_id'])) {
    //                 $finalPrice = $item['price'] - $item['discount_amount'];
    //             }

    //             $remainingQty = $item['quantity'];

    //             // 1️⃣ Get available stock (expiry first, non-expiry later)
    //             $inventories = Inventory::where('product_id', $product->id)
    //                 ->where('warehouse_id', $request->warehouse_id)
    //                 ->where('qty', '>', 0)
    //                 ->orderByRaw('expired_date IS NULL') // expiry first
    //                 ->orderBy('expired_date')
    //                 ->orderBy('created_at')
    //                 ->lockForUpdate()
    //                 ->get();

    //             // 2️⃣ Deduct from available inventory
    //             foreach ($inventories as $inventory) {
    //                 if ($remainingQty <= 0) {
    //                     break;
    //                 }

    //                 $deductQty = min($remainingQty, $inventory->qty);

    //                 $inventory->qty -= $deductQty;
    //                 $inventory->updated_by = $request->created_by;
    //                 $inventory->save();

    //                 SaleDetail::create([
    //                     'sale_id' => $sale->id,
    //                     'inventory_id' => $inventory->id,
    //                     'product_id' => $product->id,
    //                     'quantity' => $deductQty,
    //                     'price' => $item['price'],
    //                     'discount_amount' => $item['discount_amount'] ?? 0,
    //                     'discount_price' => $item['discount_price'] ?? 0,
    //                     'promotion_id' => $item['promotion_id'] ?? null,
    //                     'total' => $finalPrice * $deductQty
    //                 ]);

    //                 StockTransaction::create([
    //                     'inventory_id'    => $inventory->id,
    //                     'reference_id'    => $sale->id,
    //                     'reference_type'  => 'sale',
    //                     'reference_date' => $request->sale_date ?? now(),
    //                     'quantity_change' => $deductQty,
    //                     'type'            => 'out',
    //                     'created_by'      => $request->created_by,
    //                     'updated_by'      => $request->updated_by ?? $request->created_by
    //                 ]);

    //                 $remainingQty -= $deductQty;
    //             }

    //             // 3️⃣ If still remaining → create or update negative stock
    //             if ($remainingQty > 0) {
    //                 $negativeInventory = Inventory::firstOrCreate(
    //                     [
    //                         'product_id'   => $product->id,
    //                         'warehouse_id' => $request->warehouse_id,
    //                         'expired_date'  => null,
    //                     ],
    //                     [
    //                         'qty'         => 0,
    //                         'created_by'  => $request->created_by,
    //                         'updated_by'  => $request->updated_by ?? $request->created_by
    //                     ]
    //                 );

    //                 $negativeInventory->qty -= $remainingQty;
    //                 $negativeInventory->updated_by = $request->created_by;
    //                 $negativeInventory->save();

    //                 SaleDetail::create([
    //                     'sale_id' => $sale->id,
    //                     'inventory_id' => $negativeInventory->id,
    //                     'product_id' => $product->id,
    //                     'quantity' => $remainingQty,
    //                     'price' => $item['price'],
    //                     'discount_amount' => $item['discount_amount'] ?? 0,
    //                     'discount_price' => $item['discount_price'] ?? 0,
    //                     'promotion_id' => $item['promotion_id'] ?? null,
    //                     'total' => $finalPrice * $remainingQty
    //                 ]);

    //                 StockTransaction::create([
    //                     'inventory_id'    => $negativeInventory->id,
    //                     'reference_id'    => $sale->id,
    //                     'reference_type'  => 'sale',
    //                     'reference_date' => $request->sale_date ?? now(),
    //                     'quantity_change' => $remainingQty,
    //                     'type'            => 'out',
    //                     'created_by'      => $request->created_by
    //                 ]);
    //             }
    //         }

    //         if ($request->status_id == 7) {
    //             // 2. Create CustomerTransaction only if status changed
    //             CustomerTransaction::create([
    //                 'customer_id' => $sale->customer_id,
    //                 'sale_id' => $sale->id,
    //                 'type' => 'sale',
    //                 'amount' => - ($sale->total_amount),
    //                 'payment_id' => $sale->payment_id,
    //                 'status_id' => 7,
    //                 'pay_date' => $sale->sale_date,
    //                 'created_by' => $sale->updated_by,
    //                 'updated_by' => $sale->updated_by
    //             ]);


    //             // 3. Update customer balances
    //             $customer = $sale->customer;
    //             if ($sale->payment_id == 2 || $sale->payment_id == 3) {
    //                 $customer->balance -= $sale->total_amount;
    //             }

    //             $customer->save();
    //         }

    //         DB::commit();
    //         return new SaleResource($sale->fresh(['warehouse', 'customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy']));
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json(['error' => 'Failed to create sale', 'details' => $e->getMessage()], 500);
    //     }
    // }

    public function store(Request $request)
    {
        $request->validate([
            'customer_id' => 'required|exists:customers,id',
            'payment_id'  => 'required|exists:payment_methods,id',
            'paid_amount' => 'nullable|numeric|min:0',
            'status_id'   => 'required|exists:statuses,id',
            'remark'      => 'nullable|string|max:1000',
            'created_by'  => 'required|exists:users,id',
            'updated_by'  => 'nullable|exists:users,id',
            'sale_date'   => 'nullable|date',
            'warehouse_id'=> 'required|exists:warehouses,id',
            'products'    => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity'   => 'required|integer|min:1'
        ]);

        DB::beginTransaction();

        try {

            $saleDate   = $request->sale_date ?? now();
            $createdBy  = $request->created_by;
            $updatedBy  = $request->updated_by ?? $createdBy;
            $warehouseId = $request->warehouse_id;

            $productIds = collect($request->products)
                ->pluck('product_id')
                ->unique();

            $products = Product::whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            /* Calculate Total */

            $totalAmount = 0;

            foreach ($request->products as $item) {

                $price = !empty($item['promotion_id'])
                    ? ($item['discount_price'] ?? ($item['price'] - ($item['discount_amount'] ?? 0)))
                    : $item['price'];

                $totalAmount += $price * $item['quantity'];
            }

            $paidAmount = $request->paid_amount ?? 0;
            $dueAmount  = max($paidAmount - $totalAmount, 0);

            /* Create Sale */

            $sale = Sale::create([
                'warehouse_id' => $warehouseId,
                'customer_id' => $request->customer_id,
                'total_amount' => $totalAmount,
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_id' => $request->payment_id,
                'status_id' => $request->status_id,
                'remark' => $request->remark,
                'sale_date' => $saleDate,
                'created_by' => $createdBy,
                'updated_by' => $updatedBy,
                'is_synced' => true,
                'sync_at' => now(),
            ]);

            /* Deduct Inventory (FIFO + Negative Stock) */

            $saleDetails       = [];
            $stockTransactions = [];

            foreach ($request->products as $item) {

                $product = $products[$item['product_id']];
                $remainingQty = $item['quantity'];

                $price = !empty($item['promotion_id'])
                    ? ($item['discount_price'] ?? ($item['price'] - ($item['discount_amount'] ?? 0)))
                    : $item['price'];

                // FIFO inventories
                $inventories = Inventory::where('product_id', $product->id)
                    ->where('warehouse_id', $warehouseId)
                    ->where('qty', '>', 0)
                    ->orderByRaw('expired_date IS NULL')
                    ->orderBy('expired_date')
                    ->orderBy('created_at')
                    ->lockForUpdate()
                    ->get();

                foreach ($inventories as $inventory) {

                    if ($remainingQty <= 0) break;

                    $deductQty = min($remainingQty, $inventory->qty);

                    $inventory->decrement('qty', $deductQty);

                    $saleDetails[] = [
                        'sale_id' => $sale->id,
                        'inventory_id' => $inventory->id,
                        'product_id' => $product->id,
                        'quantity' => $deductQty,
                        'price' => $item['price'],
                        'discount_amount' => $item['discount_amount'] ?? 0,
                        'discount_price' => $item['discount_price'] ?? 0,
                        'promotion_id' => $item['promotion_id'] ?? null,
                        'total' => $price * $deductQty,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];

                    $stockTransactions[] = [
                        'inventory_id' => $inventory->id,
                        'reference_id' => $sale->id,
                        'reference_type' => 'sale',
                        'reference_date' => $saleDate,
                        'quantity_change' => $deductQty,
                        'type' => 'out',
                        'created_by' => $createdBy,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];

                    $remainingQty -= $deductQty;
                }

                /* Negative Stock (Controlled) */

                if ($remainingQty > 0) {

                    $negativeInventory = Inventory::firstOrCreate(
                        [
                            'product_id' => $product->id,
                            'warehouse_id' => $warehouseId,
                            'expired_date' => null
                        ],
                        [
                            'qty' => 0,
                            'created_by' => $createdBy,
                            'updated_by' => $updatedBy
                        ]
                    );

                    $negativeInventory->decrement('qty', $remainingQty);

                    $saleDetails[] = [
                        'sale_id' => $sale->id,
                        'inventory_id' => $negativeInventory->id,
                        'product_id' => $product->id,
                        'quantity' => $remainingQty,
                        'price' => $item['price'],
                        'discount_amount' => $item['discount_amount'] ?? 0,
                        'discount_price' => $item['discount_price'] ?? 0,
                        'promotion_id' => $item['promotion_id'] ?? null,
                        'total' => $price * $remainingQty,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];

                    $stockTransactions[] = [
                        'inventory_id' => $negativeInventory->id,
                        'reference_id' => $sale->id,
                        'reference_type' => 'sale',
                        'reference_date' => $saleDate,
                        'quantity_change' => $remainingQty,
                        'type' => 'out',
                        'created_by' => $createdBy,
                        'updated_at' => now(),
                        'created_at' => now()
                    ];
                }
            }

            SaleDetail::insert($saleDetails);
            StockTransaction::insert($stockTransactions);

            /* Customer Ledger (Only Completed Sales) */

            if ($request->status_id == 7) {

                CustomerTransaction::create([
                    'customer_id' => $sale->customer_id,
                    'reference_id' => $sale->id,
                    'type' => 'sale',
                    'amount' => -$sale->total_amount,
                    'payment_id' => $sale->payment_id,
                    'status_id' => 7,
                    'pay_date' => $sale->sale_date,
                    'created_by' => $updatedBy,
                    'updated_by' => $updatedBy
                ]);

                if (in_array($sale->payment_id, [2,3])) {
                    $sale->customer()->lockForUpdate()->decrement('balance', $sale->total_amount);
                }
            }

            DB::commit();

            return new SaleResource(
                $sale->fresh([
                    'warehouse',
                    'customer',
                    'status',
                    'paymentMethod',
                    'details.product',
                    'createdBy',
                    'updatedBy'
                ])
            );

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'error'   => 'Failed to create sale',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    public function show(string $id)
    {
        $sale = Sale::with(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy'])->findOrFail($id);
        return new SaleResource($sale);
    }

    // public function update(Request $request, string $id)
    // {
    //     $request->validate([
    //         'payment_id' => 'sometimes|required|exists:payment_methods,id',
    //         'paid_amount' => 'sometimes|required|numeric|min:0',
    //         'total_amount' => 'sometimes|required|numeric|min:0',
    //         'status_id' => 'sometimes|required|exists:statuses,id',
    //         'remark' => 'nullable|string|max:1000',
    //         'sale_date' => 'sometimes|date',
    //         'updated_by' => 'nullable|exists:users,id'
    //     ]);

    //     $sale = Sale::with('status')->findOrFail($id); // Load sale with status relation

    //     DB::beginTransaction();
    //     try {

    //         // 2. Calculate change (due_amount)
    //         $paidAmount = $request->paid_amount ?? 0;
    //         $dueAmount = $paidAmount - $request->total_amount; // change amount

    //         if ($dueAmount < 0) $dueAmount = 0; // avoid negative change

    //         if ($request->products) {
    //             foreach ($sale->details as $detail) {

    //                 $inventory = Inventory::find($detail->inventory_id);

    //                 if ($inventory) {
    //                     $inventory->qty += $detail->quantity;
    //                     $inventory->save();
    //                 }

    //                 // 3. Insert stock transaction
    //                 StockTransaction::create([
    //                     'inventory_id' => $inventory->id ?? null,
    //                     'reference_id' => $sale->id,
    //                     'reference_type' => 'sale_update',
    //                     'reference_date' => $sale->sale_date,
    //                     'quantity_change' => $detail->quantity,
    //                     'type' => 'in',
    //                     'created_by' => $sale->updated_by,
    //                     'updated_by' => $sale->updated_by,
    //                 ]);
    //             }

    //             $sale->update([
    //                 'payment_id' => $request->payment_id,
    //                 'paid_amount' => $request->paid_amount,
    //                 'total_amount' => $request->total_amount,
    //                 'due_amount' => $dueAmount,
    //                 'status_id' => $request->status_id,
    //                 'remark' => $request->remark,
    //                 'sale_date' => $request->sale_date,
    //                 'updated_by' => $request->updated_by
    //             ]);

    //             foreach ($request->products as $item) {
    //                 $product = Product::findOrFail($item['product_id']);
    //                 $finalPrice = $item['price'];

    //                 if (!empty($item['promotion_id'])) {
    //                     $finalPrice = $item['price'] - $item['discount_amount'];
    //                 }

    //                 $remainingQty = $item['quantity'];

    //                 // 1. Get available stock (expiry first, non-expiry later)
    //                 $inventories = Inventory::where('product_id', $product->id)
    //                     ->where('warehouse_id', $sale->warehouse_id)
    //                     ->where('qty', '>', 0)
    //                     ->orderByRaw('expired_date IS NULL') // expiry first
    //                     ->orderBy('expired_date')
    //                     ->orderBy('created_at')
    //                     ->lockForUpdate()
    //                     ->get();

    //                 // 2. Deduct from available inventory
    //                 foreach ($inventories as $inventory) {
    //                     if ($remainingQty <= 0) {
    //                         break;
    //                     }

    //                     $deductQty = min($remainingQty, $inventory->qty);

    //                     $inventory->qty -= $deductQty;
    //                     $inventory->updated_by = $request->updated_by;
    //                     $inventory->save();

    //                     $saleDetail = SaleDetail::where('sale_id', $sale->id)
    //                         ->where('product_id', $product->id)
    //                         ->first();

    //                     if ($saleDetail) {
    //                         $saleDetail->update([
    //                             'quantity' => $deductQty,
    //                             'price' => $item['price'],
    //                             'discount_amount' => $item['discount_amount'] ?? 0,
    //                             'discount_price' => $item['discount_price'] ?? 0,
    //                             'promotion_id' => $item['promotion_id'] ?? null,
    //                             'total' => $finalPrice * $deductQty
    //                         ]);
    //                     } else {
    //                         SaleDetail::create([
    //                             'sale_id' => $sale->id,
    //                             'inventory_id' => $inventory->id,
    //                             'product_id' => $product->id,
    //                             'quantity' => $deductQty,
    //                             'price' => $item['price'],
    //                             'discount_amount' => $item['discount_amount'] ?? 0,
    //                             'discount_price' => $item['discount_price'] ?? 0,
    //                             'promotion_id' => $item['promotion_id'] ?? null,
    //                             'total' => $finalPrice * $deductQty
    //                         ]);
    //                     }

    //                     StockTransaction::create([
    //                         'inventory_id'    => $inventory->id,
    //                         'reference_id'    => $sale->id,
    //                         'reference_type'  => 'sale_update',
    //                         'reference_date' => $request->sale_date ?? now(),
    //                         'quantity_change' => $deductQty,
    //                         'type'            => 'out',
    //                         'created_by'      => $request->updated_by,
    //                         'updated_by'      => $request->updated_by
    //                     ]);

    //                     $remainingQty -= $deductQty;
    //                 }

    //                 // 3. If still remaining → create or update negative stock
    //                 if ($remainingQty > 0) {
    //                     $negativeInventory = Inventory::firstOrCreate(
    //                         [
    //                             'product_id'   => $product->id,
    //                             'warehouse_id' => $sale->warehouse_id,
    //                             'expired_date'  => null,
    //                         ],
    //                         [
    //                             'qty'         => 0,
    //                             'created_by'  => $request->updated_by,
    //                             'updated_by'  => $request->updated_by
    //                         ]
    //                     );

    //                     $negativeInventory->qty -= $remainingQty;
    //                     $negativeInventory->updated_by = $request->updated_by;
    //                     $negativeInventory->save();

    //                     $saleDetail = SaleDetail::where('sale_id', $sale->id)
    //                         ->where('product_id', $product->id)
    //                         ->first();

    //                     if ($saleDetail) {
    //                         $saleDetail->update([
    //                             'quantity' => $remainingQty,
    //                             'price' => $item['price'],
    //                             'discount_amount' => $item['discount_amount'] ?? 0,
    //                             'discount_price' => $item['discount_price'] ?? 0,
    //                             'promotion_id' => $item['promotion_id'] ?? null,
    //                             'total' => $finalPrice * $remainingQty
    //                         ]);
    //                     } else {
    //                         SaleDetail::create([
    //                             'sale_id' => $sale->id,
    //                             'inventory_id' => $inventory->id,
    //                             'product_id' => $product->id,
    //                             'quantity' => $remainingQty,
    //                             'price' => $item['price'],
    //                             'discount_amount' => $item['discount_amount'] ?? 0,
    //                             'discount_price' => $item['discount_price'] ?? 0,
    //                             'promotion_id' => $item['promotion_id'] ?? null,
    //                             'total' => $finalPrice * $remainingQty
    //                         ]);
    //                     }

    //                     StockTransaction::create([
    //                         'inventory_id'    => $negativeInventory->id,
    //                         'reference_id'    => $sale->id,
    //                         'reference_type'  => 'sale_update',
    //                         'reference_date' => $request->sale_date ?? now(),
    //                         'quantity_change' => $remainingQty,
    //                         'type'            => 'out',
    //                         'created_by'      => $request->updated_by
    //                     ]);
    //                 }
    //             }
    //         }

    //         $old_payment = $sale->payment_id;

    //         $sale->update([
    //             'payment_id' => $request->payment_id,
    //             'paid_amount' => $request->paid_amount,
    //             'due_amount' => $dueAmount,
    //             'total_amount' => $request->total_amount,
    //             'status_id' => $request->status_id,
    //             'remark' => $request->remark,
    //             'sale_date' => $request->sale_date,
    //             'updated_by' => $request->updated_by
    //         ]);

    //         $customer = $sale->customer;

    //         if ($transaction = CustomerTransaction::where('sale_id', $sale->id)->first()) {

    //             // Update existing transaction
    //             $transaction->update([
    //                 'customer_id' => $sale->customer_id,
    //                 'amount' => - ($sale->total_amount),
    //                 'payment_id' => $sale->payment_id,
    //                 'status_id' => 7,
    //                 'pay_date' => $sale->sale_date,
    //                 'updated_by' => $sale->updated_by
    //             ]);

    //             if ($old_payment == 2 || $old_payment == 3) {
    //                 $customer->balance += $sale->total_amount;
    //             }

    //             // Update customer balance if credit
    //             if ($request->payment_id == 2 || $request->payment_id == 3) {
    //                 $customer->balance -= $sale->total_amount;
    //             }
    //         } else {
    //             // Create new transaction if it doesn’t exist
    //             CustomerTransaction::create([
    //                 'customer_id' => $sale->customer_id,
    //                 'sale_id' => $sale->id,
    //                 'type' => 'sale',
    //                 'amount' => - ($sale->total_amount),
    //                 'payment_id' => $sale->payment_id,
    //                 'status_id' => 7,
    //                 'pay_date' => $sale->sale_date,
    //                 'created_by' => $sale->updated_by,
    //                 'updated_by' => $sale->updated_by
    //             ]);

    //             // Update customer balance if credit
    //             if ($sale->payment_id == 2 || $sale->payment_id == 3) {
    //                 $customer->balance -= $sale->total_amount;
    //             }
    //         }

    //         $customer->save();

    //         DB::commit();

    //         // 4. Return updated sale resource with relationships
    //         return new SaleResource(
    //             $sale->fresh(['customer', 'status', 'paymentMethod', 'details.product', 'createdBy', 'updatedBy'])
    //         );
    //     } catch (\Exception $e) {
    //         DB::rollBack();
    //         return response()->json([
    //             'error' => 'Failed to update sale',
    //             'details' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'payment_id'  => 'sometimes|required|exists:payment_methods,id',
            'paid_amount' => 'sometimes|required|numeric|min:0',
            'status_id'   => 'sometimes|required|exists:statuses,id',
            'remark'      => 'nullable|string|max:1000',
            'sale_date'   => 'sometimes|date',
            'updated_by'  => 'required|exists:users,id',
            'products'    => 'sometimes|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity'   => 'required|integer|min:1'
        ]);

        DB::beginTransaction();

        try {

            $sale = Sale::with(['details', 'customer'])
                ->lockForUpdate()
                ->findOrFail($id);

            $updatedBy = $request->updated_by;
            $saleDate  = $request->sale_date ?? $sale->sale_date;
            $oldTotal  = $sale->total_amount;
            $oldPayment= $sale->payment_id;

            // Delete old sale details
            SaleDetail::where('sale_id', $sale->id)->delete();

            /* Recalculate New Total */

            $totalAmount = 0;

            if ($request->products) {
                /* RESTORE OLD STOCK (Rollback Previous Deduction) */
                foreach ($sale->details as $detail) {

                    Inventory::where('id', $detail->inventory_id)
                        ->lockForUpdate()
                        ->increment('qty', $detail->quantity);

                    StockTransaction::where('reference_id', $sale->id)
                    ->delete();

                    // StockTransaction::create([
                    //     'inventory_id' => $detail->inventory_id,
                    //     'reference_id' => $sale->id,
                    //     'reference_type' => 'sale_update',
                    //     'reference_date' => $saleDate,
                    //     'quantity_change' => $detail->quantity,
                    //     'type' => 'in',
                    //     'created_by' => $updatedBy,
                    // ]);
                }

                foreach ($request->products as $item) {

                    $price = !empty($item['promotion_id'])
                        ? ($item['discount_price'] ?? ($item['price'] - ($item['discount_amount'] ?? 0)))
                        : $item['price'];

                    $totalAmount += $price * $item['quantity'];
                }
            } else {
                $totalAmount = $sale->total_amount;
            }

            $paidAmount = $request->paid_amount ?? $sale->paid_amount;
            $dueAmount  = max($paidAmount - $totalAmount, 0);

            /* Deduct Stock Again (FIFO) */

            $saleDetails       = [];
            $stockTransactions = [];

            if ($request->products) {

                foreach ($request->products as $item) {

                    $remainingQty = $item['quantity'];

                    $price = !empty($item['promotion_id'])
                        ? ($item['discount_price'] ?? ($item['price'] - ($item['discount_amount'] ?? 0)))
                        : $item['price'];

                    $inventories = Inventory::where('product_id', $item['product_id'])
                        ->where('warehouse_id', $sale->warehouse_id)
                        ->where('qty', '>', 0)
                        ->orderByRaw('expired_date IS NULL')
                        ->orderBy('expired_date')
                        ->orderBy('created_at')
                        ->lockForUpdate()
                        ->get();

                    foreach ($inventories as $inventory) {

                        if ($remainingQty <= 0) break;

                        $deductQty = min($remainingQty, $inventory->qty);

                        $inventory->decrement('qty', $deductQty);

                        $saleDetails[] = [
                            'sale_id' => $sale->id,
                            'inventory_id' => $inventory->id,
                            'product_id' => $item['product_id'],
                            'quantity' => $deductQty,
                            'price' => $item['price'],
                            'discount_amount' => $item['discount_amount'] ?? 0,
                            'discount_price' => $item['discount_price'] ?? 0,
                            'promotion_id' => $item['promotion_id'] ?? null,
                            'total' => $price * $deductQty,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];

                        $stockTransactions[] = [
                            'inventory_id' => $inventory->id,
                            'reference_id' => $sale->id,
                            'reference_type' => 'sale',
                            'reference_date' => $saleDate,
                            'quantity_change' => $deductQty,
                            'type' => 'out',
                            'created_by' => $updatedBy,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];

                        $remainingQty -= $deductQty;
                    }

                    // Negative stock
                    if ($remainingQty > 0) {

                        $negativeInventory = Inventory::firstOrCreate(
                            [
                                'product_id' => $item['product_id'],
                                'warehouse_id' => $sale->warehouse_id,
                                'expired_date' => null
                            ],
                            [
                                'qty' => 0,
                                'created_by' => $updatedBy,
                                'updated_by' => $updatedBy
                            ]
                        );

                        $negativeInventory->decrement('qty', $remainingQty);

                        $saleDetails[] = [
                            'sale_id' => $sale->id,
                            'inventory_id' => $negativeInventory->id,
                            'product_id' => $item['product_id'],
                            'quantity' => $remainingQty,
                            'price' => $item['price'],
                            'discount_amount' => $item['discount_amount'] ?? 0,
                            'discount_price' => $item['discount_price'] ?? 0,
                            'promotion_id' => $item['promotion_id'] ?? null,
                            'total' => $price * $remainingQty,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];

                        $stockTransactions[] = [
                            'inventory_id' => $negativeInventory->id,
                            'reference_id' => $sale->id,
                            'reference_type' => 'sale',
                            'reference_date' => $saleDate,
                            'quantity_change' => $remainingQty,
                            'type' => 'out',
                            'created_by' => $updatedBy,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }

                SaleDetail::insert($saleDetails);
                StockTransaction::insert($stockTransactions);
            }

            /* Update Sale */

            $sale->update([
                'payment_id' => $request->payment_id ?? $sale->payment_id,
                'paid_amount' => $paidAmount,
                'total_amount' => $totalAmount,
                'due_amount' => $dueAmount,
                'status_id' => $request->status_id ?? $sale->status_id,
                'remark' => $request->remark,
                'sale_date' => $saleDate,
                'updated_by' => $updatedBy
            ]);

            /* Customer Ledger Adjustment (Accurate Reversal) */

            $customer = $sale->customer()->lockForUpdate()->first();

            if (in_array($oldPayment, [2,3])) {
                $customer->increment('balance', $oldTotal);
            }

            if (in_array($sale->payment_id, [2,3])) {
                $customer->decrement('balance', $sale->total_amount);
            }

            CustomerTransaction::updateOrCreate(
                ['reference_id' => $sale->id],
                [
                    'customer_id' => $sale->customer_id,
                    'type' => 'sale',
                    'amount' => -$sale->total_amount,
                    'payment_id' => $sale->payment_id,
                    'status_id' => 7,
                    'pay_date' => $sale->sale_date,
                    'updated_by' => $updatedBy,
                    'created_by'  => $updatedBy
                ]
            );

            DB::commit();

            return new SaleResource(
                $sale->fresh([
                    'customer',
                    'status',
                    'paymentMethod',
                    'details.product',
                    'createdBy',
                    'updatedBy'
                ])
            );

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'error'   => 'Failed to update sale',
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
            $sale = Sale::with('details')->findOrFail($id);
            $voidStatus = \App\Models\Status::where('name', 'void')->first();

            if ($sale->status_id == 7) {
                // 3. Revert customer balance if sale was on credit
                $customer = $sale->customer;
                $customer->balance += $sale->total_amount;
                $customer->save();

                // 2. Create CustomerTransaction only if status changed
                CustomerTransaction::create([
                    'customer_id' => $sale->customer_id,
                    'reference_id' => $sale->id,
                    'type' => 'sale_void',
                    'amount' => $sale->total_amount,
                    'payment_id' => $sale->payment_id,
                    'status_id' => 7,
                    'pay_date' => now(),
                    'created_by' => $request->void_by,
                    'updated_by' => $request->void_by
                ]);
            }

            // 1. Update sale status
            $sale->status_id = $voidStatus->id;
            $sale->void_at = now();
            $sale->void_by = $request->void_by;
            $sale->save();

            // 2. Restore stock to inventory
            foreach ($sale->details as $detail) {

                $inventory = Inventory::find($detail->inventory_id);

                if ($inventory) {
                    $inventory->qty += $detail->quantity;
                    $inventory->save();
                }

                // 3. Insert stock transaction
                StockTransaction::create([
                    'inventory_id' => $inventory->id ?? null,
                    'reference_id' => $sale->id,
                    'reference_type' => 'sale_void',
                    'reference_date' => $sale->sale_date,
                    'quantity_change' => $detail->quantity,
                    'type' => 'in',
                    'created_by' => $sale->void_by,
                    'updated_by' => $sale->void_by,
                ]);
            }

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
