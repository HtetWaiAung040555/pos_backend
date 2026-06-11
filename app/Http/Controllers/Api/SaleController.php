<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\StockTransaction;
use App\Models\CustomerTransaction;
use App\Models\PromotionFocAllocation;
use App\Models\PromotionReward;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

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

        $perPage = $request->get('per_page', 100);

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
            'branch_id'   => 'nullable|exists:branches,id',
            'warehouse_id'=> 'required|exists:warehouses,id',
            'is_synced'   => 'nullable|boolean',
            'order_discount_amount' => 'nullable|numeric|min:0',
            'applied_promotions' => 'nullable|array',
            'products'    => 'required|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity'   => 'required|integer|min:1',
            'products.*.price' => 'required_unless:products.*.is_foc,true|numeric|min:0',
            'products.*.original_price' => 'nullable|numeric|min:0',
            'products.*.discount_amount' => 'nullable|numeric|min:0',
            'products.*.discount_price' => 'nullable|numeric|min:0',
            'products.*.promotion_id' => 'nullable|exists:promotions,id',
            'products.*.is_foc' => 'nullable|boolean',
            'products.*.reward_id' => 'nullable|exists:promotion_rewards,id',
        ]);

        DB::beginTransaction();

        try {

            $saleDate   = $request->sale_date ?? now();
            $createdBy  = $request->created_by;
            $updatedBy  = $request->updated_by ?? $createdBy;
            $warehouseId = $request->warehouse_id;
            $isSync = (bool) ($request->is_synced ?? false);

            $promotionResult = $isSync
                ? $this->submittedPromotionResult($request)
                : $this->resolvePromotionResult($request);

            if (!$isSync) {
                $this->validateSubmittedPromotionResult($request, $promotionResult);
            }

            $productIds = collect($request->products)
                ->pluck('product_id')
                ->unique();

            $products = Product::whereIn('id', $productIds)
                ->get()
                ->keyBy('id');

            /* Calculate Total */

            $totalAmount = 0;

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
                'applied_promotions' => data_get($promotionResult, 'order.applied_promotions', $request->applied_promotions),
                'paid_amount' => $paidAmount,
                'due_amount' => $dueAmount,
                'payment_id' => $request->payment_id,
                'status_id' => $request->status_id,
                'remark' => $request->remark,
                'sale_date' => $saleDate,
                'created_by' => $createdBy,
                'updated_by' => $updatedBy,
                'is_synced' => $isSync,
                'synced_at' => now(),
            ]);

            /* Deduct Inventory (FIFO + Negative Stock) */

            $saleDetails       = [];
            $stockTransactions = [];

            foreach ($request->products as $item) {

                $product = $products[$item['product_id']];
                $remainingQty = $item['quantity'];

                $isFoc = !empty($item['is_foc']);
                $rewardId = !empty($item['reward_id']) ? (int) $item['reward_id'] : null;
                $unitPrice = $isFoc ? 0 : $item['price'];
                $discountPrice = $isFoc ? 0 : ($item['discount_price'] ?? 0);
                $discountAmount = $isFoc ? 0 : ($item['discount_amount'] ?? 0);

                // $price = !empty($item['promotion_id'])
                //     ? ($item['discount_price'] ?? ($unitPrice - $discountAmount))
                //     : $unitPrice;

                $price = !empty($item['promotion_id'])
                    ? max(0, ($item['discount_price'] ?? ($unitPrice - $discountAmount)))
                    : $unitPrice;

                if ($isFoc) {

                    $approvedQty = $remainingQty;

                    if (!$isSync && $rewardId) {

                        $approvedQty = $this->getAllowedFocQty(
                            $rewardId,
                            $remainingQty,
                            (int) $warehouseId
                        );

                        if ($approvedQty <= 0) {
                            continue;
                        }
                    }

                    $remainingQty = $approvedQty;

                    $totalDeducted = 0;

                    $focInventories = Inventory::where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('foc_qty', '>', 0)
                        ->orderByRaw('expired_date IS NULL')
                        ->orderBy('expired_date')
                        ->orderBy('created_at')
                        ->lockForUpdate()
                        ->get();

                    foreach ($focInventories as $inventory) {

                        if ($remainingQty <= 0) break;

                        $deductQty = min($remainingQty, $inventory->foc_qty);

                        $inventory->decrement('foc_qty', $deductQty);

                        $saleDetails[] = [
                            'sale_id' => $sale->id,
                            'inventory_id' => $inventory->id,
                            'product_id' => $product->id,
                            'quantity' => $deductQty,
                            'price' => 0,
                            'discount_amount' => 0,
                            'discount_price' => 0,
                            'promotion_id' => $item['promotion_id'] ?? null,
                            'is_foc' => true,
                            'reward_id' => $rewardId,
                            'total' => 0,
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
                        $totalDeducted += $deductQty;
                    }

                    if ($remainingQty > 0 && $isSync) {

                        $normalInventories = Inventory::where('product_id', $product->id)
                            ->where('warehouse_id', $warehouseId)
                            ->where('qty', '>', 0)
                            ->orderByRaw('expired_date IS NULL')
                            ->orderBy('expired_date')
                            ->orderBy('created_at')
                            ->lockForUpdate()
                            ->get();

                        foreach ($normalInventories as $inventory) {

                            if ($remainingQty <= 0) break;

                            $deductQty = min($remainingQty, $inventory->qty);

                            $inventory->decrement('qty', $deductQty);

                            $saleDetails[] = [
                                'sale_id' => $sale->id,
                                'inventory_id' => $inventory->id,
                                'product_id' => $product->id,
                                'quantity' => $deductQty,
                                'price' => 0,
                                'discount_amount' => 0,
                                'discount_price' => 0,
                                'promotion_id' => $item['promotion_id'] ?? null,
                                'is_foc' => true,
                                'reward_id' => $rewardId,
                                'total' => 0,
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
                            $totalDeducted += $deductQty;
                        }
                    }

                    if ($remainingQty > 0) {

                        if (!$isSync) {
                            throw new \RuntimeException(
                                "Insufficient FOC stock for product ID {$product->id}"
                            );
                        }

                        $negativeInventory = Inventory::firstOrCreate(
                            [
                                'product_id' => $product->id,
                                'warehouse_id' => $warehouseId,
                                'expired_date' => null
                            ],
                            [
                                'qty' => 0,
                                'foc_qty' => 0,
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
                            'price' => 0,
                            'discount_amount' => 0,
                            'discount_price' => 0,
                            'promotion_id' => $item['promotion_id'] ?? null,
                            'is_foc' => true,
                            'reward_id' => $rewardId,
                            'total' => 0,
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
                            'created_at' => now(),
                            'updated_at' => now()
                        ];

                        $totalDeducted += $remainingQty;
                    }

                    if ($rewardId && $totalDeducted > 0) {
                        $this->incrementUsedFocQty($rewardId, $totalDeducted, (int) $warehouseId);
                    }

                    continue;
                }

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

                if ($remainingQty > 0) {

                    $focInventories = Inventory::where('product_id', $product->id)
                        ->where('warehouse_id', $warehouseId)
                        ->where('foc_qty', '>', 0)
                        ->orderByRaw('expired_date IS NULL')
                        ->orderBy('expired_date')
                        ->orderBy('created_at')
                        ->lockForUpdate()
                        ->get();

                    foreach ($focInventories as $inventory) {

                        if ($remainingQty <= 0) break;

                        $deductQty = min($remainingQty, $inventory->foc_qty);

                        $inventory->decrement('foc_qty', $deductQty);

                        $saleDetails[] = [
                            'sale_id' => $sale->id,
                            'inventory_id' => $inventory->id,
                            'product_id' => $product->id,
                            'quantity' => $deductQty,
                            'price' => $unitPrice,
                            'discount_amount' => $discountAmount,
                            'discount_price' => $discountPrice,
                            'promotion_id' => $item['promotion_id'] ?? null,

                            // IMPORTANT:
                            // This is still NORMAL SALE
                            // only stock source is foc_qty
                            'is_foc' => false,

                            'reward_id' => null,
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
                            'foc_qty' => 0,
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
            $this->createSalePromotionSnapshots($sale, $promotionResult);

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

            $freshSale = $sale->fresh([
                'warehouse',
                'customer',
                'status',
                'paymentMethod',
                'details.product',
                'createdBy',
                'updatedBy'
            ]);

            return new SaleResource($this->withPromotionSnapshots($freshSale));

        } catch (ValidationException $e) {

            DB::rollBack();

            throw $e;

        } catch (\DomainException $e) {

            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage()
            ], 422);

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
        $sale = Sale::with([
            'warehouse',
            'customer',
            'status',
            'paymentMethod',
            'details.product',
            'createdBy',
            'updatedBy'
        ])->findOrFail($id);

        return new SaleResource($this->withPromotionSnapshots($sale));
    }

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
            'branch_id'   => 'nullable|exists:branches,id',
            'warehouse_id'=> 'nullable|exists:warehouses,id',
            'is_synced'   => 'nullable|boolean',
            'updated_by'  => 'required|exists:users,id',
            'products'    => 'sometimes|array|min:1',
            'products.*.product_id' => 'required|exists:products,id',
            'products.*.quantity'   => 'required|integer|min:1',
            'products.*.price' => 'required_unless:products.*.is_foc,true|numeric|min:0',
            'products.*.original_price' => 'nullable|numeric|min:0',
            'products.*.discount_amount' => 'nullable|numeric|min:0',
            'products.*.discount_price' => 'nullable|numeric|min:0',
            'products.*.promotion_id' => 'nullable|exists:promotions,id',
            'products.*.is_foc' => 'nullable|boolean',
            'products.*.reward_id' => 'nullable|exists:promotion_rewards,id',
        ]);

        Log::info("Updating sale ID {$id}", $request->all());

        DB::beginTransaction();

        try {

            $sale = Sale::with(['details', 'customer'])
                ->lockForUpdate()
                ->findOrFail($id);
            
            Log::info("Locked sale ID {$id} for update");

            $updatedBy = $request->updated_by;
            $saleDate  = $request->sale_date ?? $sale->sale_date;
            $oldTotal  = $sale->total_amount;
            $oldPayment= $sale->payment_id;
            $isSync = (bool) ($request->is_synced ?? false);
            $promotionResult = null;

            /* Recalculate New Total */

            $totalAmount = 0;

            if ($request->products) {

                StockTransaction::where('reference_id', $sale->id)->delete();

                /* RESTORE OLD STOCK (Rollback Previous Deduction) */
                foreach ($sale->details as $detail) {
                    $inventory = Inventory::where('id', $detail->inventory_id)
                        ->lockForUpdate()
                        ->first();

                    if (!$inventory) {
                        continue;
                    }

                    if ($detail->is_foc) {
                        $inventory->increment('foc_qty', $detail->quantity);
                        if (!empty($detail->reward_id)) {
                            $this->rollbackUsedFocQty(
                                (int) $detail->reward_id,
                                (int) $detail->quantity,
                                (int) $inventory->warehouse_id
                            );
                        }
                    } else {
                        $inventory->increment('qty', $detail->quantity);
                    }

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

                Log::info("Restored stock for sale ID {$id} and deleted old sale details");

                $promotionRequest = $request->duplicate(
                    null,
                    array_merge($request->all(), [
                        'warehouse_id' => $request->warehouse_id ?? $sale->warehouse_id,
                    ])
                );

                $promotionResult = $isSync
                    ? $this->submittedPromotionResult($promotionRequest)
                    : $this->resolvePromotionResult($promotionRequest);

                if (!$isSync) {
                    $this->validateSubmittedPromotionResult($promotionRequest, $promotionResult);
                }

                foreach ($request->products as $item) {

                    if (!empty($item['is_foc'])) {
                        continue;
                    }

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
                    $rewardId = !empty($item['reward_id']) ? (int) $item['reward_id'] : null;

                    $unitPrice = $isFoc ? 0 : $item['price'];
                    $discountPrice = $isFoc ? 0 : ($item['discount_price'] ?? $item['price']);
                    $discountAmount = $isFoc ? 0 : ($item['discount_amount'] ?? 0);

                    $price = !empty($item['promotion_id'])
                        ? ($discountPrice ?? ($item['price'] - ($discountAmount ?? 0)))
                        : $item['price'];
                    
                    if ($isFoc) {

                        $approvedQty = $remainingQty;

                        if ($rewardId) {
                            $approvedQty = $this->getAllowedFocQty(
                                $rewardId,
                                $remainingQty,
                                (int) $sale->warehouse_id
                            );

                            if ($approvedQty <= 0) {
                                continue;
                            }
                        }

                        $remainingQty = $approvedQty;
                        $totalDeducted = 0;

                        $focInventories = Inventory::where('product_id', $item['product_id'])
                            ->where('warehouse_id', $sale->warehouse_id)
                            ->where('foc_qty', '>', 0)
                            ->orderByRaw('expired_date IS NULL')
                            ->orderBy('expired_date')
                            ->orderBy('created_at')
                            ->lockForUpdate()
                            ->get();

                        foreach ($focInventories as $inventory) {

                            if ($remainingQty <= 0) break;

                            $deductQty = min($remainingQty, $inventory->foc_qty);

                            $inventory->decrement('foc_qty', $deductQty);

                            $saleDetails[] = [
                                'sale_id' => $sale->id,
                                'inventory_id' => $inventory->id,
                                'product_id' => $item['product_id'],
                                'quantity' => $deductQty,
                                'price' => 0,
                                'discount_amount' => 0,
                                'discount_price' => 0,
                                'promotion_id' => $item['promotion_id'] ?? null,
                                'is_foc' => true,
                                'reward_id' => $rewardId,
                                'total' => 0,
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
                            $totalDeducted += $deductQty;
                        }

                        if ($remainingQty > 0) {
                            throw new \RuntimeException("Insufficient FOC stock for product ID {$item['product_id']}");
                        }

                        // ✅ Update used_qty AFTER deduction
                        if ($rewardId && $totalDeducted > 0) {
                            $this->incrementUsedFocQty(
                                $rewardId,
                                $totalDeducted,
                                (int) $sale->warehouse_id
                            );
                        }

                        continue;
                    }

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
                            'created_by' => $updatedBy,
                            'created_at' => now(),
                            'updated_at' => now()
                        ];
                    }
                }

                SaleDetail::insert($saleDetails);
                StockTransaction::insert($stockTransactions);
                DB::table('sale_promotion_snapshots')
                    ->where('sale_id', $sale->id)
                    ->delete();

                if ($promotionResult) {
                    $this->createSalePromotionSnapshots($sale, $promotionResult);
                }
            }

            /* Update Sale */

            $sale->update([
                'payment_id' => $request->payment_id ?? $sale->payment_id,
                'paid_amount' => $paidAmount,
                'total_amount' => $totalAmount,
                'due_amount' => $dueAmount,
                'order_discount_amount' => $orderDiscount,
                'applied_promotions' => $promotionResult
                    ? data_get($promotionResult, 'order.applied_promotions', $request->applied_promotions)
                    : ($request->applied_promotions ?? $sale->applied_promotions),
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

            $freshSale = $sale->fresh([
                'warehouse',
                'customer',
                'status',
                'paymentMethod',
                'details.product',
                'createdBy',
                'updatedBy'
            ]);

            return new SaleResource($this->withPromotionSnapshots($freshSale));

        } catch (ValidationException $e) {

            DB::rollBack();

            throw $e;

        } catch (\DomainException $e) {

            DB::rollBack();

            return response()->json([
                'message' => $e->getMessage()
            ], 422);

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
            $sale = Sale::with(['details', 'customer', 'warehouse'])
                ->lockForUpdate()
                ->findOrFail($id);

            $voidStatus = \App\Models\Status::where('name', 'void')
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $sale->status_id === (int) $voidStatus->id || !is_null($sale->void_at)) {
                DB::commit();

                return response()->json([
                    'message' => 'Sale is already voided.'
                ], 200);
            }

            foreach ($sale->details as $detail) {

                $inventory = Inventory::where('id', $detail->inventory_id)
                    ->lockForUpdate()
                    ->first();

                if (!$inventory) continue;

                if ($detail->is_foc) {
                    $inventory->increment('foc_qty', $detail->quantity);

                    if (!empty($detail->reward_id)) {
                        $this->rollbackUsedFocQty(
                            (int) $detail->reward_id,
                            (int) $detail->quantity,
                            (int) $inventory->warehouse_id
                        );
                    }

                } else {
                    $inventory->increment('qty', $detail->quantity);
                }

                StockTransaction::create([
                    'inventory_id' => $inventory->id,
                    'reference_id' => $sale->id,
                    'reference_type' => 'sale_void',
                    'reference_date' => $sale->sale_date,
                    'quantity_change' => $detail->quantity,
                    'type' => 'in',
                    'created_by' => $request->void_by,
                    'updated_by' => $request->void_by,
                ]);
            }

            if ($sale->status_id == 7 && in_array($sale->payment_id, [2, 3])) {

                $customer = $sale->customer()->lockForUpdate()->first();

                $customer->increment('balance', $sale->total_amount);

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

            $sale->update([
                'status_id' => $voidStatus->id,
                'void_at' => now(),
                'void_by' => $request->void_by,
                'updated_by' => $request->void_by,
                'is_synced' => false,
                'synced_at' => null,
            ]);

            DB::commit();

            $freshSale = $sale->fresh([
                'warehouse',
                'customer',
                'status',
                'paymentMethod',
                'details.product',
                'createdBy',
                'updatedBy'
            ]);

            return (new SaleResource($this->withPromotionSnapshots($freshSale)))
                ->additional([
                    'message' => 'Sale voided successfully, stock restored, and FOC usage rolled back.'
                ]);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'error' => 'Failed to void sale',
                'details' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
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

    private function resolvePromotionResult(Request $request): array
    {
        $cart = collect($request->products ?? [])
            ->reject(fn ($item) => !empty($item['is_foc']))
            ->map(fn ($item) => [
                'product_id' => (int) $item['product_id'],
                'qty' => (int) $item['quantity'],
                'price' => (float) ($item['original_price'] ?? $item['price']),
                'original_price' => isset($item['original_price'])
                    ? (float) $item['original_price']
                    : (float) ($item['price'] ?? 0),
            ])
            ->values()
            ->all();

        if (empty($cart)) {
            return [
                'items' => [],
                'order' => [
                    'total_discount' => 0,
                    'subtotal_after_product_discounts' => 0,
                    'final_amount' => 0,
                    'applied_promotions' => [],
                ],
                'foc_items' => [],
            ];
        }

        $promotionRequest = Request::create('/api/promotions/checkprice', 'POST', [
            'branch_id' => $request->branch_id,
            'warehouse_id' => $request->warehouse_id,
            'cart' => $cart,
        ]);

        $response = app(PromotionsController::class)->checkPrice($promotionRequest);

        if ($response->getStatusCode() >= 400) {
            throw ValidationException::withMessages([
                'promotions' => 'Unable to validate promotions for this sale.',
            ]);
        }

        return json_decode($response->getContent(), true) ?: [
            'items' => [],
            'order' => [
                'total_discount' => 0,
                'applied_promotions' => [],
            ],
            'foc_items' => [],
        ];
    }

    private function submittedPromotionResult(Request $request): array
    {
        $productItems = collect($request->products ?? [])
            ->reject(fn ($item) => !empty($item['is_foc']))
            ->filter(fn ($item) => !empty($item['promotion_id']))
            ->map(fn ($item) => [
                'product_id' => (int) $item['product_id'],
                'promotion_id' => (int) $item['promotion_id'],
                'promo_type' => null,
                'discount_amount' => (float) ($item['discount_amount'] ?? 0),
                'discount_price' => isset($item['discount_price']) ? (float) $item['discount_price'] : null,
                'discount_type' => 'AMOUNT',
                'discount_value' => (float) ($item['discount_value'] ?? $item['discount_amount'] ?? 0),
            ])
            ->values()
            ->all();

        $focItems = collect($request->products ?? [])
            ->filter(fn ($item) => !empty($item['is_foc']))
            ->map(fn ($item) => [
                'product_id' => (int) $item['product_id'],
                'qty' => (int) $item['quantity'],
                'promotion_id' => !empty($item['promotion_id']) ? (int) $item['promotion_id'] : null,
                'reward_id' => !empty($item['reward_id']) ? (int) $item['reward_id'] : null,
            ])
            ->values()
            ->all();

        $lineTotal = collect($request->products ?? [])
            ->reject(fn ($item) => !empty($item['is_foc']))
            ->sum(fn ($item) => ((float) ($item['discount_price'] ?? $item['price'])) * (int) $item['quantity']);

        $orderDiscount = (float) ($request->order_discount_amount ?? 0);

        return [
            'items' => $productItems,
            'order' => [
                'total_discount' => $orderDiscount,
                'subtotal_after_product_discounts' => $lineTotal,
                'final_amount' => max(0, $lineTotal - $orderDiscount),
                'applied_promotions' => $request->applied_promotions ?? [],
            ],
            'foc_items' => $focItems,
        ];
    }

    private function validateSubmittedPromotionResult(Request $request, array $promotionResult): void
    {
        $expectedProductPrices = collect($promotionResult['items'] ?? [])
            ->filter(fn ($item) => array_key_exists('discount_price', $item))
            ->reduce(function ($carry, $item) {
                $carry[(int) $item['product_id']] = (float) $item['discount_price'];

                return $carry;
            }, []);
        $expectedProductPromotionIds = collect($promotionResult['items'] ?? [])
            ->groupBy(fn ($item) => (int) $item['product_id'])
            ->map(fn ($items) => $items
                ->pluck('promotion_id')
                ->filter()
                ->map(fn ($promotionId) => (int) $promotionId)
                ->unique()
                ->values()
                ->all()
            );

        foreach (collect($request->products ?? [])->reject(fn ($item) => !empty($item['is_foc'])) as $index => $item) {
            $productId = (int) $item['product_id'];
            $submittedPromotionId = !empty($item['promotion_id']) ? (int) $item['promotion_id'] : null;

            if (
                $submittedPromotionId
                && !in_array($submittedPromotionId, $expectedProductPromotionIds[$productId] ?? [], true)
            ) {
                throw ValidationException::withMessages([
                    "products.{$index}.promotion_id" => "Promotion is not valid for product {$productId}.",
                ]);
            }

            if (!array_key_exists($productId, $expectedProductPrices)) {
                continue;
            }

            if (!$submittedPromotionId) {
                throw ValidationException::withMessages([
                    "products.{$index}.promotion_id" => "Promotion ID is required for product {$productId}.",
                ]);
            }

            if (!array_key_exists('discount_price', $item)) {
                throw ValidationException::withMessages([
                    "products.{$index}.discount_price" => "Promotion discount price is required for product {$productId}.",
                ]);
            }

            if (!$this->moneyEquals((float) $item['discount_price'], $expectedProductPrices[$productId])) {
                throw ValidationException::withMessages([
                    "products.{$index}.discount_price" => "Promotion discount price is invalid for product {$productId}.",
                ]);
            }
        }

        $expectedOrderDiscount = (float) data_get($promotionResult, 'order.total_discount', 0);
        $submittedOrderDiscount = (float) ($request->order_discount_amount ?? 0);

        if (!$this->moneyEquals($submittedOrderDiscount, $expectedOrderDiscount)) {
            throw ValidationException::withMessages([
                'order_discount_amount' => 'Order discount amount does not match the current promotion result.',
            ]);
        }

        $expectedFocItems = collect($promotionResult['foc_items'] ?? [])
            ->mapWithKeys(function ($item) {
                $key = $this->promotionFocKey(
                    $item['product_id'] ?? null,
                    $item['promotion_id'] ?? null,
                    $item['reward_id'] ?? null
                );

                return [$key => (int) ($item['qty'] ?? 0)];
            });

        $submittedFocItems = collect($request->products ?? [])
            ->filter(fn ($item) => !empty($item['is_foc']));

        foreach ($submittedFocItems as $index => $item) {
            $key = $this->promotionFocKey(
                $item['product_id'] ?? null,
                $item['promotion_id'] ?? null,
                $item['reward_id'] ?? null
            );

            $allowedQty = (int) ($expectedFocItems[$key] ?? 0);
            $submittedQty = (int) ($item['quantity'] ?? 0);

            if ($allowedQty <= 0 || $submittedQty > $allowedQty) {
                throw ValidationException::withMessages([
                    "products.{$index}.quantity" => 'Submitted FOC item is not allowed by the current promotion result.',
                ]);
            }
        }
    }

    private function createSalePromotionSnapshots(Sale $sale, array $promotionResult): void
    {
        $snapshots = [];
        $promotionIds = collect($promotionResult['items'] ?? [])
            ->pluck('promotion_id')
            ->merge(collect(data_get($promotionResult, 'order.applied_promotions', []))->pluck('promotion_id'))
            ->merge(collect($promotionResult['foc_items'] ?? [])->pluck('promotion_id'))
            ->filter()
            ->map(fn ($promotionId) => (int) $promotionId)
            ->unique()
            ->values();

        if ($promotionIds->isEmpty()) {
            return;
        }

        $promotions = Promotion::whereIn('id', $promotionIds)
            ->get()
            ->keyBy('id');

        foreach ($promotionResult['items'] ?? [] as $item) {
            $promotion = $promotions[(int) $item['promotion_id']] ?? null;

            if (!$promotion) continue;

            $snapshots[] = $this->promotionSnapshotPayload(
                $sale,
                $promotion,
                $item,
                (float) ($item['discount_amount'] ?? 0),
                (float) data_get($promotionResult, 'order.final_amount', $sale->total_amount)
            );
        }

        foreach (data_get($promotionResult, 'order.applied_promotions', []) as $item) {
            $promotion = $promotions[(int) $item['promotion_id']] ?? null;

            if (!$promotion) continue;

            $snapshots[] = $this->promotionSnapshotPayload(
                $sale,
                $promotion,
                $item,
                (float) ($item['discount'] ?? 0),
                (float) data_get($promotionResult, 'order.final_amount', $sale->total_amount)
            );
        }

        foreach ($promotionResult['foc_items'] ?? [] as $item) {
            $promotion = $promotions[(int) $item['promotion_id']] ?? null;

            if (!$promotion) continue;

            $snapshots[] = $this->promotionSnapshotPayload(
                $sale,
                $promotion,
                $item,
                0,
                (float) data_get($promotionResult, 'order.final_amount', $sale->total_amount)
            );
        }

        if (!empty($snapshots)) {
            DB::table('sale_promotion_snapshots')->insert($snapshots);
        }
    }

    private function withPromotionSnapshots(Sale $sale): Sale
    {
        $snapshots = DB::table('sale_promotion_snapshots')
            ->where('sale_id', $sale->id)
            ->orderBy('id')
            ->get()
            ->map(function ($snapshot) {
                return [
                    'id' => $snapshot->id,
                    'promotion_id' => $snapshot->promotion_id,
                    'promo_type' => $snapshot->promo_type,
                    'condition_type' => $snapshot->condition_type,
                    'promo_mode' => $snapshot->promo_mode,
                    'snapshot' => $snapshot->snapshot_json
                        ? json_decode($snapshot->snapshot_json, true)
                        : null,
                    'discount_amount' => (float) $snapshot->discount_amount,
                    'final_amount' => (float) $snapshot->final_amount,
                    'created_at' => $snapshot->created_at,
                ];
            })
            ->values()
            ->all();

        $sale->setAttribute('promotion_snapshots', $snapshots);

        return $sale;
    }

    private function promotionSnapshotPayload(Sale $sale, Promotion $promotion, array $snapshot, float $discountAmount, float $finalAmount): array
    {
        return [
            'sale_id' => $sale->id,
            'promotion_id' => $promotion->id,
            'promo_type' => $promotion->promo_type,
            'condition_type' => $promotion->condition_type,
            'promo_mode' => $promotion->promo_mode,
            'snapshot_json' => json_encode($snapshot),
            'discount_amount' => $discountAmount,
            'final_amount' => $finalAmount,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function promotionFocKey($productId, $promotionId, $rewardId): string
    {
        return (int) $productId . ':' . (int) $promotionId . ':' . (int) $rewardId;
    }

    private function moneyEquals(float $left, float $right): bool
    {
        return abs($left - $right) < 0.01;
    }

    private function getAllowedFocQty(int $rewardId, int $requestedQty, ?int $warehouseId = null): int
    {
        $reward = PromotionReward::select(['id', 'promotion_id', 'product_id'])
            ->find($rewardId);

        if (!$reward) return 0;

        $allocationQuery = PromotionFocAllocation::where('promotion_id', $reward->promotion_id)
            ->where('product_id', $reward->product_id);

        if ($warehouseId) {
            $allocationQuery->where('allocated_warehouse_id', $warehouseId);
        }

        $allocation = $allocationQuery->first();

        if (!$allocation) return 0;

        $remaining = max(0, $allocation->allocated_qty - $allocation->used_qty);

        return min($requestedQty, $remaining);
    }

    private function incrementUsedFocQty(int $rewardId, int $qty, ?int $warehouseId = null): void
    {
        if ($qty <= 0) return;

        $reward = PromotionReward::select(['promotion_id', 'product_id'])
            ->lockForUpdate()
            ->find($rewardId);

        if (!$reward) return;

        $allocationQuery = PromotionFocAllocation::where('promotion_id', $reward->promotion_id)
            ->where('product_id', $reward->product_id);

        if ($warehouseId) {
            $allocationQuery->where('allocated_warehouse_id', $warehouseId);
        }

        $allocation = $allocationQuery->lockForUpdate()->first();

        if (!$allocation) return;

        $newUsed = $allocation->used_qty + $qty;

        if ($newUsed > $allocation->allocated_qty) {
            throw new \RuntimeException("FOC allocation exceeded for product {$reward->product_id}");
        }

        $allocation->used_qty = $newUsed;
        $allocation->save();
    }

    private function rollbackUsedFocQty(int $rewardId, int $qty, ?int $warehouseId = null): void
    {
        if ($qty <= 0) return;

        $reward = PromotionReward::select(['promotion_id', 'product_id'])
            ->lockForUpdate()
            ->find($rewardId);

        if (!$reward) return;

        $allocationQuery = PromotionFocAllocation::where('promotion_id', $reward->promotion_id)
            ->where('product_id', $reward->product_id);

        if ($warehouseId) {
            $allocationQuery->where('allocated_warehouse_id', $warehouseId);
        }

        $allocation = $allocationQuery->lockForUpdate()->first();

        if (!$allocation) return;

        $allocation->used_qty = max(0, $allocation->used_qty - $qty);
        $allocation->save();
    }

}
