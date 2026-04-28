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
use Illuminate\Support\Facades\Log;

use function Symfony\Component\Clock\now;

class SaleController extends Controller
{

    public function index(Request $request)
    {
        $query = SaleController::build($request)
            ->select('sales.*')
            ->with([
                'customer',
                'status',
                'warehouse',
                'paymentMethod',
                'details.product',
                'createdBy',
                'updatedBy'
            ])
            ->orderByDesc('sales.sale_date');

        $perPage = $request->get('per_page', 50);

        $sales = $query->paginate($perPage);

        return SaleResource::collection($sales);
    }

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
            'order_discount_amount' => 'nullable|numeric|min:0',
            'applied_promotions' => 'nullable|array',
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

            // foreach ($request->products as $item) {

            //     $price = !empty($item['promotion_id'])
            //         ? ($item['discount_price'] ?? ($item['price'] - ($item['discount_amount'] ?? 0)))
            //         : $item['price'];

            //     $totalAmount += $price * $item['quantity'];
            // }

            $totalAmount = collect($request->products)->sum(function ($item) {
                if (!empty($item['is_foc'])) return 0;

                $price = $item['discount_price'] ?? $item['price'];

                return $price * $item['quantity'];
            });

            $paidAmount = $request->paid_amount ?? 0;
            $dueAmount  = max($paidAmount - $totalAmount, 0);

            $orderDiscount = $request->order_discount_amount ?? 0;
            $totalAmount = max($totalAmount - $orderDiscount, 0);

            /* Create Sale */

            $sale = Sale::create([
                'id' => $request->id,
                'warehouse_id' => $warehouseId,
                'customer_id' => $request->customer_id,
                'total_amount' => $totalAmount,
                'order_discount_amount' => $orderDiscount,
                'applied_promotions' => $request->applied_promotions,
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

                $isFoc = !empty($item['is_foc']);
                $unitPrice = $isFoc ? 0 : $item['price'];
                $discountPrice = $isFoc ? 0 : ($item['discount_price'] ?? 0);
                $discountAmount = $isFoc ? 0 : ($item['discount_amount'] ?? 0);

                $price = !empty($item['promotion_id'])
                    ? ($item['discount_price'] ?? ($unitPrice - $discountAmount))
                    : $unitPrice;

                Log::info("Price for product {$product->id}: $price (Unit: $unitPrice, Discount: $discountAmount, FOC: $isFoc)");

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
                        'price' => $unitPrice,
                        'discount_amount' => $discountAmount,
                        'discount_price' => $discountPrice,
                        'promotion_id' => $item['promotion_id'] ?? null,
                        'is_foc' => $isFoc,
                        'reward_id' => $item['reward_id'] ?? null,
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
                        'price' => $unitPrice,
                        'discount_amount' => $discountAmount,
                        'discount_price' => $discountPrice,
                        'promotion_id' => $item['promotion_id'] ?? null,
                        'is_foc' => $isFoc,
                        'reward_id' => $item['reward_id'] ?? null,
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
    //         'payment_id'  => 'sometimes|required|exists:payment_methods,id',
    //         'paid_amount' => 'sometimes|required|numeric|min:0',
    //         'status_id'   => 'sometimes|required|exists:statuses,id',
    //         'remark'      => 'nullable|string|max:1000',
    //         'sale_date'   => 'sometimes|date',
    //         'updated_by'  => 'required|exists:users,id',
    //         'products'    => 'sometimes|array|min:1',
    //         'products.*.product_id' => 'required|exists:products,id',
    //         'products.*.quantity'   => 'required|integer|min:1'
    //     ]);

    //     DB::beginTransaction();

    //     try {

    //         $sale = Sale::with(['details', 'customer'])
    //             ->lockForUpdate()
    //             ->findOrFail($id);

    //         $updatedBy = $request->updated_by;
    //         $saleDate  = $request->sale_date ?? $sale->sale_date;
    //         $oldTotal  = $sale->total_amount;
    //         $oldPayment= $sale->payment_id;

    //         /* Recalculate New Total */

    //         $totalAmount = 0;

    //         if ($request->products) {

    //             /* RESTORE OLD STOCK (Rollback Previous Deduction) */
    //             foreach ($sale->details as $detail) {

    //                 Inventory::where('id', $detail->inventory_id)
    //                     ->lockForUpdate()
    //                     ->increment('qty', $detail->quantity);

    //                 StockTransaction::where('reference_id', $sale->id)
    //                 ->delete();

    //                 // StockTransaction::create([
    //                 //     'inventory_id' => $detail->inventory_id,
    //                 //     'reference_id' => $sale->id,
    //                 //     'reference_type' => 'sale_update',
    //                 //     'reference_date' => $saleDate,
    //                 //     'quantity_change' => $detail->quantity,
    //                 //     'type' => 'in',
    //                 //     'created_by' => $updatedBy,
    //                 // ]);
    //             }

    //             // Delete old sale details
    //             SaleDetail::where('sale_id', $sale->id)->delete();

    //             foreach ($request->products as $item) {

    //                 $price = !empty($item['promotion_id'])
    //                     ? ($item['discount_price'] ?? ($item['price'] - ($item['discount_amount'] ?? 0)))
    //                     : $item['price'];

    //                 $totalAmount += $price * $item['quantity'];
    //             }
    //         } else {
    //             $totalAmount = $sale->total_amount;
    //         }

    //         $paidAmount = $request->paid_amount ?? $sale->paid_amount;
    //         $dueAmount  = max($paidAmount - $totalAmount, 0);

    //         /* Deduct Stock Again (FIFO) */

    //         $saleDetails       = [];
    //         $stockTransactions = [];

    //         if ($request->products) {

    //             foreach ($request->products as $item) {

    //                 $remainingQty = $item['quantity'];

    //                 $price = !empty($item['promotion_id'])
    //                     ? ($item['discount_price'] ?? ($item['price'] - ($item['discount_amount'] ?? 0)))
    //                     : $item['price'];

    //                 $inventories = Inventory::where('product_id', $item['product_id'])
    //                     ->where('warehouse_id', $sale->warehouse_id)
    //                     ->where('qty', '>', 0)
    //                     ->orderByRaw('expired_date IS NULL')
    //                     ->orderBy('expired_date')
    //                     ->orderBy('created_at')
    //                     ->lockForUpdate()
    //                     ->get();

    //                 foreach ($inventories as $inventory) {

    //                     if ($remainingQty <= 0) break;

    //                     $deductQty = min($remainingQty, $inventory->qty);

    //                     $inventory->decrement('qty', $deductQty);

    //                     $saleDetails[] = [
    //                         'sale_id' => $sale->id,
    //                         'inventory_id' => $inventory->id,
    //                         'product_id' => $item['product_id'],
    //                         'quantity' => $deductQty,
    //                         'price' => $item['price'],
    //                         'discount_amount' => $item['discount_amount'] ?? 0,
    //                         'discount_price' => $item['discount_price'] ?? 0,
    //                         'promotion_id' => $item['promotion_id'] ?? null,
    //                         'total' => $price * $deductQty,
    //                         'created_at' => now(),
    //                         'updated_at' => now()
    //                     ];

    //                     $stockTransactions[] = [
    //                         'inventory_id' => $inventory->id,
    //                         'reference_id' => $sale->id,
    //                         'reference_type' => 'sale',
    //                         'reference_date' => $saleDate,
    //                         'quantity_change' => $deductQty,
    //                         'type' => 'out',
    //                         'created_by' => $updatedBy,
    //                         'created_at' => now(),
    //                         'updated_at' => now()
    //                     ];

    //                     $remainingQty -= $deductQty;
    //                 }

    //                 // Negative stock
    //                 if ($remainingQty > 0) {

    //                     $negativeInventory = Inventory::firstOrCreate(
    //                         [
    //                             'product_id' => $item['product_id'],
    //                             'warehouse_id' => $sale->warehouse_id,
    //                             'expired_date' => null
    //                         ],
    //                         [
    //                             'qty' => 0,
    //                             'created_by' => $updatedBy,
    //                             'updated_by' => $updatedBy
    //                         ]
    //                     );

    //                     $negativeInventory->decrement('qty', $remainingQty);

    //                     $saleDetails[] = [
    //                         'sale_id' => $sale->id,
    //                         'inventory_id' => $negativeInventory->id,
    //                         'product_id' => $item['product_id'],
    //                         'quantity' => $remainingQty,
    //                         'price' => $item['price'],
    //                         'discount_amount' => $item['discount_amount'] ?? 0,
    //                         'discount_price' => $item['discount_price'] ?? 0,
    //                         'promotion_id' => $item['promotion_id'] ?? null,
    //                         'total' => $price * $remainingQty,
    //                         'created_at' => now(),
    //                         'updated_at' => now()
    //                     ];

    //                     $stockTransactions[] = [
    //                         'inventory_id' => $negativeInventory->id,
    //                         'reference_id' => $sale->id,
    //                         'reference_type' => 'sale',
    //                         'reference_date' => $saleDate,
    //                         'quantity_change' => $remainingQty,
    //                         'type' => 'out',
    //                         'created_by' => $updatedBy,
    //                         'created_at' => now(),
    //                         'updated_at' => now()
    //                     ];
    //                 }
    //             }

    //             SaleDetail::insert($saleDetails);
    //             StockTransaction::insert($stockTransactions);
    //         }

    //         /* Update Sale */

    //         $sale->update([
    //             'payment_id' => $request->payment_id ?? $sale->payment_id,
    //             'paid_amount' => $paidAmount,
    //             'total_amount' => $totalAmount,
    //             'due_amount' => $dueAmount,
    //             'status_id' => $request->status_id ?? $sale->status_id,
    //             'remark' => $request->remark,
    //             'sale_date' => $saleDate,
    //             'updated_by' => $updatedBy
    //         ]);

    //         /* Customer Ledger Adjustment (Accurate Reversal) */

    //         $customer = $sale->customer()->lockForUpdate()->first();

    //         if (in_array($oldPayment, [2,3])) {
    //             $customer->increment('balance', $oldTotal);
    //         }

    //         if (in_array($sale->payment_id, [2,3])) {
    //             $customer->decrement('balance', $sale->total_amount);
    //         }

    //         CustomerTransaction::updateOrCreate(
    //             ['reference_id' => $sale->id],
    //             [
    //                 'customer_id' => $sale->customer_id,
    //                 'type' => 'sale',
    //                 'amount' => -$sale->total_amount,
    //                 'payment_id' => $sale->payment_id,
    //                 'status_id' => 7,
    //                 'pay_date' => $sale->sale_date,
    //                 'updated_by' => $updatedBy,
    //                 'created_by'  => $updatedBy
    //             ]
    //         );

    //         DB::commit();

    //         return new SaleResource(
    //             $sale->fresh([
    //                 'customer',
    //                 'status',
    //                 'paymentMethod',
    //                 'details.product',
    //                 'createdBy',
    //                 'updatedBy'
    //             ])
    //         );

    //     } catch (\Throwable $e) {

    //         DB::rollBack();

    //         return response()->json([
    //             'error'   => 'Failed to update sale',
    //             'details' => $e->getMessage()
    //         ], 500);
    //     }
    // }

    public function update(Request $request, string $id)
    {
        $request->validate([
            'payment_id'  => 'sometimes|required|exists:payment_methods,id',
            'paid_amount' => 'sometimes|required|numeric|min:0',
            'order_discount_amount' => 'nullable|numeric|min:0',
            'applied_promotions' => 'nullable|array',
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

            /* Recalculate New Total */

            $totalAmount = 0;

            if ($request->products) {

                StockTransaction::where('reference_id', $sale->id)->delete();

                /* RESTORE OLD STOCK (Rollback Previous Deduction) */
                foreach ($sale->details as $detail) {

                    Inventory::where('id', $detail->inventory_id)
                        ->lockForUpdate()
                        ->increment('qty', $detail->quantity);

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

                // Delete old sale details
                SaleDetail::where('sale_id', $sale->id)->delete();

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
            $orderDiscount = $request->order_discount_amount ?? $sale->order_discount_amount ?? 0;
            $totalAmount = max($totalAmount - $orderDiscount, 0);

            /* Deduct Stock Again (FIFO) */

            $saleDetails       = [];
            $stockTransactions = [];

            if ($request->products) {

                foreach ($request->products as $item) {

                    $remainingQty = $item['quantity'];

                    $isFoc = !empty($item['is_foc']);

                    $unitPrice = $isFoc ? 0 : $item['price'];
                    $discountPrice = $isFoc ? 0 : ($item['discount_price'] ?? $item['price']);
                    $discountAmount = $isFoc ? 0 : ($item['discount_amount'] ?? 0);

                    $price = !empty($item['promotion_id'])
                        ? ($discountPrice ?? ($item['price'] - ($discountAmount ?? 0)))
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
                            'price' => $unitPrice,
                            'discount_amount' => $discountAmount,
                            'discount_price' => $discountPrice,
                            'promotion_id' => $item['promotion_id'] ?? null,
                            'is_foc' => $isFoc,
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
                            'price' => $unitPrice,
                            'discount_amount' => $discountAmount,
                            'discount_price' => $discountPrice,
                            'promotion_id' => $item['promotion_id'] ?? null,
                            'is_foc' => $isFoc,
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
                'order_discount_amount' => $orderDiscount,
                'applied_promotions' => $request->applied_promotions ?? $sale->applied_promotions,
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

    public static function build(Request $request)
    {
        return Sale::query()
            ->when($request->filled('sale_id'), function ($q) use ($request) {
                $q->where('sales.id', $request->sale_id);
            })

            ->when($request->filled('customer_search'), function ($q) use ($request) {

                $keyword = $request->customer_search;

                $q->join('customers', 'sales.customer_id', '=', 'customers.id')
                  ->where(function ($sub) use ($keyword) {
                      $sub->where('customers.id', $keyword)
                          ->orWhere('customers.name', 'like', "%{$keyword}%");
                  });
            })

            ->when($request->filled('status_id'), function ($q) use ($request) {
                $q->where('sales.status_id', $request->status_id);
            })

            ->when($request->filled('payment_id'), function ($q) use ($request) {
                $q->where('sales.payment_id', $request->payment_id);
            })

            ->when($request->filled('warehouse_id'), function ($q) use ($request) {
                $q->where('sales.warehouse_id', $request->warehouse_id);
            })

            ->when($request->filled('product_search'), function ($q) use ($request) {

                $keyword = $request->product_search;

                $q->join('sale_details', 'sales.id', '=', 'sale_details.sale_id')
                  ->join('products', 'sale_details.product_id', '=', 'products.id')
                  ->where(function ($sub) use ($keyword) {
                      $sub->where('products.barcode', $keyword)
                          ->orWhere('products.name', 'like', "%{$keyword}%");
                  });
            })

            ->when($request->filled('start_date') && $request->filled('end_date'), function ($q) use ($request) {
                $q->whereBetween('sales.sale_date', [$request->start_date, $request->end_date]);
            })

            ->when($request->filled('start_date') && !$request->filled('end_date'), function ($q) use ($request) {
                $q->whereDate('sales.sale_date', '>=', $request->start_date);
            })

            ->when($request->filled('end_date') && !$request->filled('start_date'), function ($q) use ($request) {
                $q->whereDate('sales.sale_date', '<=', $request->end_date);
            })

            ->distinct();
    }

    public function export(Request $request)
    {
        $sales = SaleController::build($request)
            ->select('sales.*')
            ->with(['customer','details.product'])
            ->orderByDesc('sales.sale_date')
            ->get();

        return SaleResource::collection($sales);
    }

    public function dashboard(Request $request)
    {
        $query = SaleController::build($request);

        $stats = (clone $query)
            ->selectRaw("
                COUNT(DISTINCT sales.id) as total_invoice,
                COALESCE(SUM(sales.total_amount),0) as total_sales,
                COALESCE(SUM(CASE WHEN sales.payment_id = 1 THEN sales.total_amount END),0) as total_cash,
                COALESCE(SUM(CASE WHEN sales.payment_id = 4 THEN sales.total_amount END),0) as total_kpay,
                COALESCE(SUM(CASE WHEN sales.payment_id = 3 THEN sales.total_amount END),0) as total_wallet
            ")
            ->first();

        return response()->json($stats);
    }

}
