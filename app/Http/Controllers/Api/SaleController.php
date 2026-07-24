<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\SaleResource;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Promotion;
use App\Models\Sale;
use App\Models\SaleDetail;
use App\Models\StockTransaction;
use App\Models\CustomerTransaction;
use App\Models\PromotionFocAllocation;
use App\Models\PromotionReward;
use App\Services\SellingPriceService;
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
                'branch',
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
            'products.*.product_unit_id' => 'nullable|exists:product_units,id',
            'products.*.unit_id' => 'nullable|exists:units,id',
            'products.*.unit_name' => 'nullable|string|max:255',
            'products.*.unit_quantity' => 'nullable|numeric|min:0',
            'products.*.base_quantity' => 'nullable|numeric|min:0',
            'products.*.conversion_to_base' => 'nullable|numeric|min:0',
            'products.*.unit_barcode' => 'nullable|string|max:255',
            'products.*.price_range_id' => 'nullable|exists:product_unit_price_ranges,id',
            'products.*.quantity'   => 'required|integer|min:1',
            'products.*.price' => 'required_unless:products.*.is_foc,true|numeric|min:0',
            'products.*.original_price' => 'nullable|numeric|min:0',
            'products.*.discount_amount' => 'nullable|numeric|min:0',
            'products.*.discount_price' => 'nullable|numeric|min:0',
            'products.*.promotion_id' => 'nullable|exists:promotions,id',
            'products.*.is_foc' => 'nullable|boolean',
            'products.*.reward_id' => 'nullable|exists:promotion_rewards,id',
        ]);

        Log::info("Creating new sale", $request->all());

        DB::beginTransaction();

        try {

            $saleDate   = $request->sale_date ?? now();
            $createdBy  = $request->created_by;
            $updatedBy  = $request->updated_by ?? $createdBy;
            $warehouseId = $request->warehouse_id;
            $branchId = $this->resolveSaleBranchId($request->branch_id, $warehouseId);
            $isSync = (bool) ($request->is_synced ?? false);

            $request->merge(['branch_id' => $branchId]);

            if (!$isSync) {
                $this->applySellingPricesToSaleRequest($request);
            }

            $this->normalizeSaleItemUomRequest($request);

            $promotionResult = $isSync
                ? $this->submittedPromotionResult($request)
                : $this->resolvePromotionResult($request);

            if (!$isSync) {
                $this->validateSubmittedPromotionResult($request, $promotionResult);
                $this->applyPromotionResultToSaleRequest($request, $promotionResult);
                $this->normalizeSaleItemUomRequest($request);
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
                'branch_id' => $branchId,
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
                $isFoc = !empty($item['is_foc']);
                $remainingQty = $isFoc
                    ? (int) $item['quantity']
                    : (int) $item['base_quantity'];
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

                        $saleDetails[] = array_merge([
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
                        ], $this->saleDetailUomSnapshot($item, $deductQty));

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

                            $saleDetails[] = array_merge([
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
                            ], $this->saleDetailUomSnapshot($item, $deductQty));

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

                        $saleDetails[] = array_merge([
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
                        ], $this->saleDetailUomSnapshot($item, $remainingQty));

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

                    $saleDetails[] = array_merge([
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
                        'total' => $price * $this->unitQuantityFromBase($item, $deductQty),
                        'created_at' => now(),
                        'updated_at' => now()
                    ], $this->saleDetailUomSnapshotFromBase($item, $deductQty));

                    $stockTransactions[] = array_merge([
                        'inventory_id' => $inventory->id,
                        'reference_id' => $sale->id,
                        'reference_type' => 'sale',
                        'reference_date' => $saleDate,
                        'quantity_change' => $deductQty,
                        'type' => 'out',
                        'created_by' => $createdBy,
                        'created_at' => now(),
                        'updated_at' => now()
                    ], $this->stockTransactionUomSnapshotFromBase($item, $deductQty));

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

                        $saleDetails[] = array_merge([
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
                            'total' => $price * $this->unitQuantityFromBase($item, $deductQty),
                            'created_at' => now(),
                            'updated_at' => now()
                        ], $this->saleDetailUomSnapshotFromBase($item, $deductQty));

                        $stockTransactions[] = array_merge([
                            'inventory_id' => $inventory->id,
                            'reference_id' => $sale->id,
                            'reference_type' => 'sale',
                            'reference_date' => $saleDate,
                            'quantity_change' => $deductQty,
                            'type' => 'out',
                            'created_by' => $createdBy,
                            'created_at' => now(),
                            'updated_at' => now()
                        ], $this->stockTransactionUomSnapshotFromBase($item, $deductQty));

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

                    $saleDetails[] = array_merge([
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
                        'total' => $price * $this->unitQuantityFromBase($item, $remainingQty),
                        'created_at' => now(),
                        'updated_at' => now()
                    ], $this->saleDetailUomSnapshotFromBase($item, $remainingQty));

                    $stockTransactions[] = array_merge([
                        'inventory_id' => $negativeInventory->id,
                        'reference_id' => $sale->id,
                        'reference_type' => 'sale',
                        'reference_date' => $saleDate,
                        'quantity_change' => $remainingQty,
                        'type' => 'out',
                        'created_by' => $createdBy,
                        'updated_at' => now(),
                        'created_at' => now()
                    ], $this->stockTransactionUomSnapshotFromBase($item, $remainingQty));
                }
            }

            SaleDetail::insert($saleDetails);
            $this->insertSaleStockTransactions($stockTransactions);
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
                'branch',
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
            'branch',
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
            'products.*.product_unit_id' => 'nullable|exists:product_units,id',
            'products.*.unit_id' => 'nullable|exists:units,id',
            'products.*.unit_name' => 'nullable|string|max:255',
            'products.*.unit_quantity' => 'nullable|numeric|min:0',
            'products.*.base_quantity' => 'nullable|numeric|min:0',
            'products.*.conversion_to_base' => 'nullable|numeric|min:0',
            'products.*.unit_barcode' => 'nullable|string|max:255',
            'products.*.price_range_id' => 'nullable|exists:product_unit_price_ranges,id',
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
            $warehouseId = $request->warehouse_id ?? $sale->warehouse_id;
            $branchId = $this->resolveSaleBranchId($request->branch_id ?? $sale->branch_id, $warehouseId);
            $isSync = (bool) ($request->is_synced ?? false);
            $promotionResult = null;

            $request->merge([
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
            ]);

            if (!$isSync && $request->has('products')) {
                $this->applySellingPricesToSaleRequest($request, $warehouseId);
            }

            if ($request->has('products')) {
                $this->normalizeSaleItemUomRequest($request);
            }

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
                        'warehouse_id' => $warehouseId,
                    ])
                );

                $promotionResult = $isSync
                    ? $this->submittedPromotionResult($promotionRequest)
                    : $this->resolvePromotionResult($promotionRequest);

                if (!$isSync) {
                    $this->validateSubmittedPromotionResult($promotionRequest, $promotionResult);
                    $this->applyPromotionResultToSaleRequest($request, $promotionResult);
                    $this->normalizeSaleItemUomRequest($request);
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

                    $isFoc = !empty($item['is_foc']);
                    $remainingQty = $isFoc
                        ? (int) $item['quantity']
                        : (int) $item['base_quantity'];
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
                                (int) $warehouseId
                            );

                            if ($approvedQty <= 0) {
                                continue;
                            }
                        }

                        $remainingQty = $approvedQty;
                        $totalDeducted = 0;

                        $focInventories = Inventory::where('product_id', $item['product_id'])
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

                            $saleDetails[] = array_merge([
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
                            ], $this->saleDetailUomSnapshot($item, $deductQty));

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
                                (int) $warehouseId
                            );
                        }

                        continue;
                    }

                    $inventories = Inventory::where('product_id', $item['product_id'])
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

                        $saleDetails[] = array_merge([
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
                            'total' => $price * $this->unitQuantityFromBase($item, $deductQty),
                            'created_at' => now(),
                            'updated_at' => now()
                        ], $this->saleDetailUomSnapshotFromBase($item, $deductQty));

                        $stockTransactions[] = array_merge([
                            'inventory_id' => $inventory->id,
                            'reference_id' => $sale->id,
                            'reference_type' => 'sale',
                            'reference_date' => $saleDate,
                            'quantity_change' => $deductQty,
                            'type' => 'out',
                            'created_by' => $updatedBy,
                            'created_at' => now(),
                            'updated_at' => now()
                        ], $this->stockTransactionUomSnapshotFromBase($item, $deductQty));

                        $remainingQty -= $deductQty;
                    }

                    // Negative stock
                    if ($remainingQty > 0) {

                        $negativeInventory = Inventory::firstOrCreate(
                            [
                                'product_id' => $item['product_id'],
                                'warehouse_id' => $warehouseId,
                                'expired_date' => null
                            ],
                            [
                                'qty' => 0,
                                'created_by' => $updatedBy,
                                'updated_by' => $updatedBy
                            ]
                        );

                        $negativeInventory->decrement('qty', $remainingQty);

                        $saleDetails[] = array_merge([
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
                            'total' => $price * $this->unitQuantityFromBase($item, $remainingQty),
                            'created_at' => now(),
                            'updated_at' => now()
                        ], $this->saleDetailUomSnapshotFromBase($item, $remainingQty));

                        $stockTransactions[] = array_merge([
                            'inventory_id' => $negativeInventory->id,
                            'reference_id' => $sale->id,
                            'reference_type' => 'sale',
                            'reference_date' => $saleDate,
                            'quantity_change' => $remainingQty,
                            'type' => 'out',
                            'created_by' => $updatedBy,
                            'created_at' => now(),
                            'updated_at' => now()
                        ], $this->stockTransactionUomSnapshotFromBase($item, $remainingQty));
                    }
                }

                SaleDetail::insert($saleDetails);
                $this->insertSaleStockTransactions($stockTransactions);
                DB::table('sale_promotion_snapshots')
                    ->where('sale_id', $sale->id)
                    ->delete();

                if ($promotionResult) {
                    $this->createSalePromotionSnapshots($sale, $promotionResult);
                }
            }

            /* Update Sale */

            $sale->update([
                'branch_id' => $branchId,
                'warehouse_id' => $warehouseId,
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
                'branch',
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
                'branch',
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

            ->when($request->filled('branch_id'), function ($q) use ($request) {
                $q->where('sales.branch_id', $request->branch_id);
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
            ->with(['branch','warehouse','customer','details.product'])
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

    private function applySellingPricesToSaleRequest(Request $request, ?int $warehouseId = null): void
    {
        $branchId = $request->branch_id ? (int) $request->branch_id : null;
        $warehouseId ??= $request->warehouse_id ? (int) $request->warehouse_id : null;

        if (!$branchId && $warehouseId) {
            $branchId = Branch::where('warehouse_id', $warehouseId)->value('id');
        }

        if (!$branchId) {
            return;
        }

        $priceResolver = app(SellingPriceService::class);
        $products = collect($request->products ?? [])
            ->map(function ($item) use ($priceResolver, $branchId) {
                if (!empty($item['is_foc'])) {
                    return $item;
                }

                $pricing = $priceResolver->resolve(
                    (int) $item['product_id'],
                    $branchId,
                    !empty($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
                    (float) ($item['quantity'] ?? 1),
                    !empty($item['unit_id']) ? (int) $item['unit_id'] : null
                );

                $item['submitted_price'] = isset($item['price']) ? (float) $item['price'] : null;
                $item['original_price'] = (float) $pricing['price'];
                $item['price'] = (float) $pricing['price'];
                $item['product_unit_id'] = $pricing['product_unit_id'] ?? ($item['product_unit_id'] ?? null);
                $item['unit_id'] = $pricing['unit_id'] ?? ($item['unit_id'] ?? null);
                $item['unit_name'] = $pricing['unit_name'] ?? ($item['unit_name'] ?? null);
                $item['conversion_to_base'] = $pricing['conversion_to_base'] ?? ($item['conversion_to_base'] ?? null);
                $item['price_source'] = $pricing['source'];
                $item['branch_product_id'] = $pricing['branch_product_id'];
                $item['branch_product_unit_price_id'] = $pricing['branch_product_unit_price_id'];
                $item['product_unit_price_range_id'] = $pricing['product_unit_price_range_id'];
                $item['branch_product_unit_price_range_id'] = $pricing['branch_product_unit_price_range_id'];

                return $item;
            })
            ->values()
            ->all();

        $request->merge(['products' => $products]);
    }

    private function resolveSaleBranchId($branchId, ?int $warehouseId): ?int
    {
        $branchId = !empty($branchId) ? (int) $branchId : null;

        if ($branchId) {
            $branchWarehouseId = Branch::whereKey($branchId)->value('warehouse_id');

            if ($warehouseId && (int) $branchWarehouseId !== (int) $warehouseId) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'warehouse_id must belong to the selected branch.',
                ]);
            }

            return $branchId;
        }

        if (!$warehouseId) {
            return null;
        }

        return Branch::where('warehouse_id', $warehouseId)->value('id');
    }

    private function resolvePromotionResult(Request $request): array
    {
        $cart = collect($request->products ?? [])
            ->reject(fn ($item) => !empty($item['is_foc']))
            ->map(fn ($item) => [
                'product_id' => (int) $item['product_id'],
                'product_unit_id' => !empty($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
                'unit_id' => !empty($item['unit_id']) ? (int) $item['unit_id'] : null,
                'qty' => (int) $item['quantity'],
                'base_qty' => (float) ($item['base_quantity'] ?? $item['quantity']),
                'price' => (float) ($item['original_price'] ?? $item['price']),
                'original_price' => isset($item['original_price'])
                    ? (float) $item['original_price']
                    : (float) ($item['price'] ?? 0),
            ])
            ->values()
            ->all();

        if (empty($cart)) {
            return [
                'priced_items' => [],
                'items' => [],
                'order' => [
                    'total_discount' => 0,
                    'subtotal_after_product_discounts' => 0,
                    'final_amount' => 0,
                    'applied_promotions' => [],
                ],
                'foc_items' => [],
                'promotion_warnings' => [],
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
            'priced_items' => [],
            'items' => [],
            'order' => [
                'total_discount' => 0,
                'applied_promotions' => [],
            ],
            'foc_items' => [],
            'promotion_warnings' => [],
        ];
    }

    private function applyPromotionResultToSaleRequest(Request $request, array $promotionResult): void
    {
        $discountsByLine = collect($promotionResult['items'] ?? [])
            ->filter(fn ($item) => !empty($item['line_key']) && array_key_exists('discount_price', $item))
            ->groupBy('line_key')
            ->map(fn ($items) => $items->last());

        $regularProducts = collect($request->products ?? [])
            ->reject(fn ($item) => ! empty($item['is_foc']))
            ->values();
        $focProducts = collect($request->products ?? [])
            ->filter(fn ($item) => ! empty($item['is_foc']))
            ->values();
        $productsBySourceLine = $regularProducts->mapWithKeys(
            fn ($item, $index) => [$this->saleItemPromotionKey($item, $index) => $item]
        );
        $pricedItems = collect($promotionResult['priced_items'] ?? []);

        $applyDiscount = function (array $item, $discount): array {
            if (! $discount) {
                unset(
                    $item['promotion_id'],
                    $item['promo_type'],
                    $item['discount_type'],
                    $item['discount_value'],
                    $item['discount_price']
                );

                $item['discount_amount'] = 0;

                return $item;
            }

            $originalPrice = (float) ($item['original_price'] ?? $item['price'] ?? 0);
            $discountPrice = (float) $discount['discount_price'];

            $item['promotion_id'] = (int) $discount['promotion_id'];
            $item['promo_type'] = $discount['promo_type'] ?? null;
            $item['discount_type'] = $discount['discount_type'] ?? null;
            $item['discount_value'] = isset($discount['discount_value'])
                ? (float) $discount['discount_value']
                : null;
            $item['discount_price'] = $discountPrice;
            $item['discount_amount'] = max(0, $originalPrice - $discountPrice);

            return $item;
        };

        if ($pricedItems->isNotEmpty()) {
            $products = $pricedItems
                ->map(function ($pricedItem) use ($productsBySourceLine, $discountsByLine, $applyDiscount) {
                    $sourceLineKey = $pricedItem['source_line_key'] ?? $pricedItem['line_key'] ?? null;
                    $item = $sourceLineKey ? $productsBySourceLine->get($sourceLineKey) : null;

                    if (! $item) {
                        return null;
                    }

                    $quantity = (float) ($pricedItem['qty'] ?? 0);

                    if ($quantity <= 0 || abs($quantity - round($quantity)) > 0.000001) {
                        throw ValidationException::withMessages([
                            'products' => 'Promotion quantity allocation must produce positive whole-number sales quantities.',
                        ]);
                    }

                    $uomSnapshot = $this->saleDetailUomSnapshot($item, $quantity);
                    $item = array_merge($item, $uomSnapshot);
                    $item['quantity'] = (int) round($quantity);
                    $item['price'] = (float) ($pricedItem['price'] ?? $item['price'] ?? 0);
                    $item['original_price'] = (float) ($pricedItem['original_price'] ?? $item['price']);

                    $lineKey = $pricedItem['line_key'] ?? null;
                    $discount = $lineKey ? ($discountsByLine[$lineKey] ?? null) : null;

                    return $applyDiscount($item, $discount);
                })
                ->filter()
                ->concat($focProducts)
                ->values()
                ->all();
        } else {
            $products = $regularProducts
                ->map(function ($item, $index) use ($discountsByLine, $applyDiscount) {
                    $lineKey = $this->saleItemPromotionKey($item, $index);

                    return $applyDiscount($item, $discountsByLine[$lineKey] ?? null);
                })
                ->concat($focProducts)
                ->values()
                ->all();
        }

        $request->merge([
            'products' => $products,
            'order_discount_amount' => (float) data_get($promotionResult, 'order.total_discount', 0),
            'applied_promotions' => data_get($promotionResult, 'order.applied_promotions', []),
        ]);
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
        $expectedProductDiscountsByLine = collect($promotionResult['items'] ?? [])
            ->filter(fn ($item) => !empty($item['line_key']) && array_key_exists('discount_price', $item))
            ->groupBy('line_key')
            ->map(fn ($items) => $items->last());

        $expectedProductDiscountsByProduct = collect($promotionResult['items'] ?? [])
            ->filter(fn ($item) => array_key_exists('discount_price', $item))
            ->groupBy(fn ($item) => (int) ($item['product_id'] ?? 0))
            ->map(fn ($items) => $items->last());

        $expectedProductPromotionIdsByLine = collect($promotionResult['items'] ?? [])
            ->filter(fn ($item) => !empty($item['line_key']))
            ->groupBy('line_key')
            ->map(fn ($items) => $items
                ->pluck('promotion_id')
                ->filter()
                ->map(fn ($promotionId) => (int) $promotionId)
                ->unique()
                ->values()
                ->all()
            );

        $expectedProductPromotionIdsByProduct = collect($promotionResult['items'] ?? [])
            ->groupBy(fn ($item) => (int) ($item['product_id'] ?? 0))
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
            $lineKey = $this->saleItemPromotionKey($item, $index);
            $submittedPromotionId = !empty($item['promotion_id']) ? (int) $item['promotion_id'] : null;
            $expectedDiscount = $expectedProductDiscountsByLine[$lineKey]
                ?? $expectedProductDiscountsByProduct[$productId]
                ?? null;
            $expectedPromotionIds = $expectedProductPromotionIdsByLine[$lineKey]
                ?? $expectedProductPromotionIdsByProduct[$productId]
                ?? [];

            if (
                $submittedPromotionId
                && !in_array($submittedPromotionId, $expectedPromotionIds, true)
            ) {
                throw ValidationException::withMessages([
                    "products.{$index}.promotion_id" => "Promotion is not valid for product {$productId}.",
                ]);
            }

            if (!$expectedDiscount || !array_key_exists('discount_price', $expectedDiscount)) {
                continue;
            }

            if (
                $submittedPromotionId
                && array_key_exists('discount_price', $item)
                && !$this->moneyEquals((float) $item['discount_price'], (float) $expectedDiscount['discount_price'])
            ) {
                throw ValidationException::withMessages([
                    "products.{$index}.discount_price" => "Promotion discount price is invalid for product {$productId}.",
                ]);
            }
        }

        $expectedOrderDiscount = (float) data_get($promotionResult, 'order.total_discount', 0);
        $submittedOrderDiscount = (float) ($request->order_discount_amount ?? 0);

        if ($submittedOrderDiscount > 0 && !$this->moneyEquals($submittedOrderDiscount, $expectedOrderDiscount)) {
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

    private function saleItemPromotionKey(array $item, int $index): string
    {
        return implode(':', [
            $index,
            (int) ($item['product_id'] ?? 0),
            (int) ($item['product_unit_id'] ?? 0),
        ]);
    }

    private function normalizeSaleItemUomRequest(Request $request): void
    {
        $items = collect($request->products ?? [])
            ->map(function (array $item, int $index) {
                if (!empty($item['is_foc'])) {
                    return $item;
                }

                $productId = (int) $item['product_id'];
                $productUnit = null;

                if (!empty($item['product_unit_id'])) {
                    $productUnit = ProductUnit::with('unit')
                        ->where('product_id', $productId)
                        ->find($item['product_unit_id']);

                    if (!$productUnit) {
                        throw ValidationException::withMessages([
                            "products.{$index}.product_unit_id" => "The selected product unit does not belong to product {$productId}.",
                        ]);
                    }
                } elseif (!empty($item['unit_id'])) {
                    $productUnit = ProductUnit::with('unit')
                        ->where('product_id', $productId)
                        ->where('unit_id', $item['unit_id'])
                        ->first();
                }

                $conversion = $productUnit
                    ? (float) $productUnit->conversion_to_base
                    : 1.0;

                if ($conversion <= 0) {
                    throw ValidationException::withMessages([
                        "products.{$index}.product_unit_id" => "Product {$productId} must have a positive conversion to its base unit.",
                    ]);
                }

                $unitQuantity = (float) $item['quantity'];
                $calculatedBaseQuantity = $unitQuantity * $conversion;
                $baseQuantity = (int) round($calculatedBaseQuantity);

                if (abs($calculatedBaseQuantity - $baseQuantity) > 0.000001) {
                    throw ValidationException::withMessages([
                        "products.{$index}.quantity" => "Product {$productId} converts to a fractional base quantity, but inventory stores whole quantities.",
                    ]);
                }

                $item['product_unit_id'] = $productUnit?->id;
                $item['unit_id'] = $productUnit?->unit_id ?? ($item['unit_id'] ?? null);
                $item['unit_name'] = $productUnit?->unit?->name ?? ($item['unit_name'] ?? null);
                $item['unit_barcode'] = $productUnit?->barcode ?? ($item['unit_barcode'] ?? null);
                $item['unit_quantity'] = $unitQuantity;
                $item['base_quantity'] = $baseQuantity;
                $item['conversion_to_base'] = $conversion;

                return $item;
            })
            ->values()
            ->all();

        $request->merge(['products' => $items]);
    }

    private function saleDetailUomSnapshot(array $item, float $quantity): array
    {
        $conversion = isset($item['conversion_to_base'])
            ? (float) $item['conversion_to_base']
            : null;

        $baseQuantity = null;

        if ($conversion !== null) {
            $baseQuantity = $quantity * $conversion;
        } elseif (isset($item['base_quantity'], $item['quantity']) && (float) $item['quantity'] > 0) {
            $baseQuantity = (float) $item['base_quantity'] * ($quantity / (float) $item['quantity']);
        }

        return [
            'product_unit_id' => !empty($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
            'unit_id' => !empty($item['unit_id']) ? (int) $item['unit_id'] : null,
            'unit_name' => $item['unit_name'] ?? null,
            'unit_quantity' => $quantity,
            'base_quantity' => $baseQuantity,
            'conversion_to_base' => $conversion,
            'unit_barcode' => $item['unit_barcode'] ?? null,
            'price_range_id' => $item['price_range_id'] ?? $item['product_unit_price_range_id'] ?? null,
        ];
    }

    private function saleDetailUomSnapshotFromBase(array $item, float $baseQuantity): array
    {
        return [
            'product_unit_id' => !empty($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
            'unit_id' => !empty($item['unit_id']) ? (int) $item['unit_id'] : null,
            'unit_name' => $item['unit_name'] ?? null,
            'unit_quantity' => $this->unitQuantityFromBase($item, $baseQuantity),
            'base_quantity' => $baseQuantity,
            'conversion_to_base' => (float) ($item['conversion_to_base'] ?? 1),
            'unit_barcode' => $item['unit_barcode'] ?? null,
            'price_range_id' => $item['price_range_id'] ?? $item['product_unit_price_range_id'] ?? null,
        ];
    }

    private function stockTransactionUomSnapshotFromBase(array $item, float $baseQuantity): array
    {
        $snapshot = $this->saleDetailUomSnapshotFromBase($item, $baseQuantity);

        return [
            'product_unit_id' => $snapshot['product_unit_id'],
            'unit_id' => $snapshot['unit_id'],
            'unit_quantity' => $snapshot['unit_quantity'],
            'base_quantity' => $snapshot['base_quantity'],
            'conversion_to_base' => $snapshot['conversion_to_base'],
        ];
    }

    private function insertSaleStockTransactions(array $transactions): void
    {
        $uomDefaults = [
            'product_unit_id' => null,
            'unit_id' => null,
            'unit_quantity' => null,
            'base_quantity' => null,
            'conversion_to_base' => null,
        ];

        $transactions = array_map(
            fn (array $transaction) => array_merge($uomDefaults, $transaction),
            $transactions
        );

        StockTransaction::insert($transactions);
    }

    private function unitQuantityFromBase(array $item, float $baseQuantity): float
    {
        $conversion = (float) ($item['conversion_to_base'] ?? 1);

        return $conversion > 0
            ? $baseQuantity / $conversion
            : $baseQuantity;
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
