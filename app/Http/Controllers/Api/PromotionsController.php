<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\PromotionBranch;
use App\Models\PromotionCondition;
use App\Models\PromotionFocAllocation;
use App\Models\PromotionReward;
use App\Models\PromotionWarehouse;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

class PromotionsController extends Controller
{

    public function index()
    {
        $this->refreshPromotionLifecycleStatuses();

        $promotions = Promotion::with([
            'products',
            'conditions.product',
            'rewards.product',
            'focAllocations.product',
            'branches',
            'warehouses',
            'status',
            'createdBy',
            'updatedBy',
            'voidBy',
        ])->latest()->get();

        return PromotionResource::collection($promotions);
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'description' => 'nullable|string',
                'promo_type' => 'required|in:PRODUCT_DISCOUNT,ORDER_DISCOUNT,FOC,PRICE_OVERRIDE',
                'condition_type' => 'nullable|in:NONE,ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
                'promo_mode' => 'nullable|in:NORMAL,TIER,MULTIPLIER,MIX_MATCH',
                'discount_type' => 'nullable|in:PERCENT,AMOUNT',
                'discount_value' => 'nullable|numeric|min:0',
                'max_reward_value' => 'nullable|numeric|min:0',
                'override_price' => 'nullable|numeric|min:0',
                'start_at' => 'required|date',
                'end_at' => 'required|date|after_or_equal:start_at',
                'status_id' => 'nullable|exists:statuses,id',
                'warehouse_id' => 'nullable|exists:warehouses,id',
                'created_by' => 'required|exists:users,id',

                'products' => 'nullable|array',
                'products.*' => 'integer|exists:products,id',
                'branch_scope_type' => 'nullable|in:ALL,SELECTED',
                'branch_ids' => 'nullable|array',
                'branch_ids.*' => 'integer|exists:branches,id',
                'warehouse_scope_type' => 'nullable|in:ALL,SELECTED',
                'warehouse_ids' => 'nullable|array',
                'warehouse_ids.*' => 'integer|exists:warehouses,id',

                'target_value' => 'nullable|numeric|min:0',
                'product_id' => 'nullable|exists:products,id',

                'tiers' => 'nullable|array',
                'tiers.*.condition' => 'nullable|array',
                'tiers.*.condition.condition_type' => 'required_with:tiers.*.condition|in:ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
                'tiers.*.condition.target_value' => 'required_with:tiers.*.condition|numeric|min:0',
                'tiers.*.condition.product_id' => 'nullable|exists:products,id',
                'tiers.*.condition.operator' => 'nullable|string|max:10',
                'tiers.*.condition.target_value_to' => 'nullable|numeric|min:0',
                'tiers.*.conditions' => 'nullable|array',
                'tiers.*.conditions.*.condition_type' => 'required_with:tiers.*.conditions|in:ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
                'tiers.*.conditions.*.target_value' => 'required_with:tiers.*.conditions|numeric|min:0',
                'tiers.*.conditions.*.product_id' => 'nullable|exists:products,id',
                'tiers.*.conditions.*.operator' => 'nullable|string|max:10',
                'tiers.*.conditions.*.target_value_to' => 'nullable|numeric|min:0',

                'tiers.*.reward' => 'nullable|array',
                'tiers.*.reward.reward_value' => 'nullable|numeric|min:0',
                'tiers.*.reward.reward_qty' => 'nullable|integer|min:1',
                'tiers.*.reward.product_id' => 'nullable|exists:products,id',
                'tiers.*.rewards' => 'nullable|array',
                'tiers.*.rewards.*.product_id' => 'nullable|exists:products,id',
                'tiers.*.rewards.*.reward_qty' => 'nullable|integer|min:1',
                'tiers.*.rewards.*.reward_value' => 'nullable|numeric|min:0',

                'foc_allocations' => 'nullable|array',
                'foc_allocations.*.product_id' => 'required_with:foc_allocations|exists:products,id',
                'foc_allocations.*.allocated_qty' => 'required_with:foc_allocations|integer|min:0',
            ]);

            $branchIds = collect($validated['branch_ids'] ?? [])->unique()->values()->all();
            $warehouseIds = collect($validated['warehouse_ids'] ?? [])->unique()->values()->all();
            if (empty($warehouseIds) && !empty($validated['warehouse_id'])) {
                $warehouseIds = [(int) $validated['warehouse_id']];
            }
            $branchScopeType = $validated['branch_scope_type'] ?? (empty($branchIds) ? 'ALL' : 'SELECTED');
            $warehouseScopeType = $validated['warehouse_scope_type'] ?? (empty($warehouseIds) ? 'ALL' : 'SELECTED');
            $promoMode = $validated['promo_mode'] ?? ($validated['promo_type'] === 'PRICE_OVERRIDE' ? 'MIX_MATCH' : 'NORMAL');
            $conditionType = $validated['condition_type']
                ?? $validated['tiers'][0]['condition']['condition_type']
                ?? $validated['tiers'][0]['conditions'][0]['condition_type']
                ?? 'NONE';

            if ($branchScopeType === 'SELECTED' && empty($branchIds)) {
                throw ValidationException::withMessages([
                    'branch_ids' => 'branch_ids is required when branch_scope_type is SELECTED.',
                ]);
            }

            if ($warehouseScopeType === 'SELECTED' && empty($warehouseIds)) {
                throw ValidationException::withMessages([
                    'warehouse_ids' => 'warehouse_ids is required when warehouse_scope_type is SELECTED.',
                ]);
            }

            if ($validated['promo_type'] === 'PRICE_OVERRIDE' && !isset($validated['override_price'])) {
                throw ValidationException::withMessages([
                    'override_price' => 'override_price is required for price override promotions.',
                ]);
            }

            $tiers = $this->normalizePromotionTiers($validated);

            Log::info("Creating promotion with data", $validated);

            $promotion = DB::transaction(function () use (
                $validated,
                $branchIds,
                $warehouseIds,
                $branchScopeType,
                $warehouseScopeType,
                $promoMode,
                $conditionType,
                $tiers
            ) {
                $promotion = Promotion::create([
                    'name' => $validated['name'],
                    'description' => $validated['description'] ?? null,
                    'promo_type' => $validated['promo_type'],
                    'condition_type' => $conditionType,
                    'promo_mode' => $promoMode,
                    'discount_type' => $validated['discount_type'] ?? 'AMOUNT',
                    'discount_value' => $validated['discount_value'] ?? 0,
                    'max_reward_value' => $validated['max_reward_value'] ?? null,
                    'override_price' => $validated['override_price'] ?? null,
                    'branch_scope_type' => $branchScopeType,
                    'warehouse_scope_type' => $warehouseScopeType,
                    'start_at' => $validated['start_at'],
                    'end_at' => $validated['end_at'],
                    'status_id' => $this->promotionLifecycleStatusId($validated['start_at'], $validated['end_at']),
                    'created_by' => $validated['created_by'],
                    'updated_by' => $validated['created_by'],
                    'is_synced' => false,
                    'synced_at' => null,
                ]);

                Log::info("Promotion created with ID {$promotion->id}");

                if (array_key_exists('products', $validated)) {
                    $promotion->products()->sync($validated['products'] ?? []);
                }

                $targetBranchIds = $this->syncPromotionBranches($promotion, $branchScopeType, $branchIds);
                $targetWarehouseIds = $this->syncPromotionWarehouses(
                    $promotion,
                    $warehouseScopeType,
                    $warehouseIds,
                    $targetBranchIds
                );

                if ($promotion->promo_type === 'FOC') {
                    $allocationWarehouseIds = $targetWarehouseIds;

                    foreach ($validated['foc_allocations'] ?? [] as $allocation) {
                        $productId = (int) $allocation['product_id'];
                        $qty = (int) $allocation['allocated_qty'];

                        $exists = PromotionFocAllocation::where('product_id', $productId)
                            ->where('promotion_id', '!=', $promotion->id)
                            ->whereHas('promotion', fn($q) => $q
                                ->whereNull('void_at')
                                ->whereIn('status_id', $this->promotionReservableStatusIds())
                            )
                            ->lockForUpdate()
                            ->exists();

                        if ($exists) {
                            throw ValidationException::withMessages([
                                'foc_allocations' => "Product {$productId} already allocated in another active FOC promotion.",
                            ]);
                        }

                        foreach ($allocationWarehouseIds as $warehouseId) {
                            $this->allocateFocStock($productId, $warehouseId, $qty);

                            PromotionFocAllocation::create([
                                'promotion_id' => $promotion->id,
                                'product_id' => $productId,
                                'allocated_qty' => $qty,
                                'used_qty' => 0,
                                'allocated_warehouse_id' => $warehouseId,
                            ]);

                            Log::info("Allocated FOC stock for product {$productId} with quantity {$qty} in warehouse {$warehouseId}");
                        }
                    }
                }

                foreach ($tiers as $index => $tierData) {
                    $tier = $index + 1;
                    $conditions = $tierData['conditions'] ?? [];

                    foreach ($conditions as $conditionIndex => $condition) {
                        $promotion->conditions()->create([
                            'product_id' => $condition['product_id'] ?? null,
                            'group_no' => $condition['group_no'] ?? ($conditionIndex + 1),
                            'condition_type' => $condition['condition_type'],
                            'operator' => $condition['operator'] ?? '>=',
                            'target_value' => $condition['target_value'],
                            'target_value_to' => $condition['target_value_to'] ?? null,
                            'tier' => $tier,
                        ]);
                    }

                    foreach ($tierData['rewards'] ?? [] as $reward) {
                        $promotion->rewards()->create([
                            'reward_type' => $promotion->promo_type === 'FOC' ? 'FREE_PRODUCT' : 'DISCOUNT',
                            'product_id' => $reward['product_id'] ?? null,
                            'reward_qty' => $reward['reward_qty'] ?? null,
                            'reward_value' => $reward['reward_value'] ?? null,
                            'tier' => $tier
                        ]);
                    }
                }

                return $promotion;
            });

            return (new PromotionResource(
                $promotion->load([
                    'products',
                    'conditions.product',
                    'rewards.product',
                    'focAllocations.product',
                    'branches',
                    'warehouses',
                ])
            ))->response()->setStatusCode(201);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create promotion',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], 500);
        }
    }

    public function show(string $id)
    {
        $promotion = Promotion::with([
            'products',
            'conditions.product',
            'rewards.product',
            'focAllocations.product',
            'branches',
            'warehouses',
        ])->findOrFail($id);

        return new PromotionResource($promotion);
    }

    public function update(Request $request, string $id)
    {
        $promotion = Promotion::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'promo_type' => 'sometimes|required|in:PRODUCT_DISCOUNT,ORDER_DISCOUNT,FOC,PRICE_OVERRIDE',
            'condition_type' => 'nullable|in:NONE,ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
            'promo_mode' => 'nullable|in:NORMAL,TIER,MULTIPLIER,MIX_MATCH',
            'start_at' => 'sometimes|required|date',
            'end_at' => 'sometimes|required|date|after_or_equal:start_at',
            'status_id' => 'nullable|exists:statuses,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'updated_by' => 'nullable|exists:users,id',
            'products' => 'nullable|array',
            'products.*' => 'integer|exists:products,id',
            'discount_type' => 'nullable|in:PERCENT,AMOUNT',
            'discount_value' => 'nullable|numeric|min:0',
            'max_reward_value' => 'nullable|numeric|min:0',
            'override_price' => 'nullable|numeric|min:0',
            'branch_scope_type' => 'nullable|in:ALL,SELECTED',
            'branch_ids' => 'nullable|array',
            'branch_ids.*' => 'integer|exists:branches,id',
            'warehouse_scope_type' => 'nullable|in:ALL,SELECTED',
            'warehouse_ids' => 'nullable|array',
            'warehouse_ids.*' => 'integer|exists:warehouses,id',
            'target_value' => 'nullable|numeric|min:0',
            'product_id' => 'nullable|exists:products,id',
            'tiers' => 'nullable|array',
            'tiers.*.condition' => 'nullable|array',
            'tiers.*.condition.id' => 'nullable|exists:promotion_conditions,id',
            'tiers.*.condition.condition_type' => 'required_with:tiers.*.condition|in:ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
            'tiers.*.condition.target_value' => 'required_with:tiers.*.condition|numeric|min:0',
            'tiers.*.condition.product_id' => 'nullable|exists:products,id',
            'tiers.*.condition.operator' => 'nullable|string|max:10',
            'tiers.*.condition.target_value_to' => 'nullable|numeric|min:0',
            'tiers.*.conditions' => 'nullable|array',
            'tiers.*.conditions.*.id' => 'nullable|exists:promotion_conditions,id',
            'tiers.*.conditions.*.condition_type' => 'required_with:tiers.*.conditions|in:ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
            'tiers.*.conditions.*.target_value' => 'required_with:tiers.*.conditions|numeric|min:0',
            'tiers.*.conditions.*.product_id' => 'nullable|exists:products,id',
            'tiers.*.conditions.*.operator' => 'nullable|string|max:10',
            'tiers.*.conditions.*.target_value_to' => 'nullable|numeric|min:0',
            'tiers.*.reward' => 'nullable|array',
            'tiers.*.reward.id' => 'nullable|exists:promotion_rewards,id',
            'tiers.*.reward.reward_value' => 'nullable|numeric|min:0',
            'tiers.*.reward.reward_qty' => 'nullable|integer|min:1',
            'tiers.*.reward.product_id' => 'nullable|exists:products,id',
            'tiers.*.rewards' => 'nullable|array',
            'tiers.*.rewards.*.id' => 'nullable|exists:promotion_rewards,id',
            'tiers.*.rewards.*.product_id' => 'nullable|exists:products,id',
            'tiers.*.rewards.*.reward_qty' => 'nullable|integer|min:1',
            'tiers.*.rewards.*.reward_value' => 'nullable|numeric|min:0',
            'foc_allocations' => 'nullable|array',
            'foc_allocations.*.id' => 'nullable|exists:promotion_foc_allocations,id',
            'foc_allocations.*.product_id' => 'required_with:foc_allocations|exists:products,id',
            'foc_allocations.*.allocated_warehouse_id' => 'nullable|exists:warehouses,id',
            'foc_allocations.*.allocated_qty' => 'required_with:foc_allocations|integer|min:0',
        ]);

        Log::info("Updating promotion ID {$id} with data", $validated);

        DB::beginTransaction();

        try {
            $currentStatusId = $this->promotionLifecycleStatusId($promotion->start_at, $promotion->end_at);
            $appliedStatusId = $this->statusIdByName('applied');
            $inactiveStatusId = $this->statusIdByName('inactive');

            if ((int) $currentStatusId === (int) $inactiveStatusId) {
                throw ValidationException::withMessages([
                    'status_id' => 'Inactive promotions cannot be updated.',
                ]);
            }

            // if ((int) $currentStatusId === (int) $appliedStatusId) {
            //     $allowedAppliedFields = ['name', 'end_at', 'updated_by'];
            //     $blockedFields = array_values(array_diff(array_keys($validated), $allowedAppliedFields));

            //     if (!empty($blockedFields)) {
            //         throw ValidationException::withMessages([
            //             'status_id' => 'Applied promotions can only update name and end_at. Blocked fields: ' . implode(', ', $blockedFields),
            //         ]);
            //     }
            // }

            $targetPromoType = $validated['promo_type'] ?? $promotion->promo_type;
            $promoMode = $validated['promo_mode']
                ?? (
                    $targetPromoType === 'PRICE_OVERRIDE' && $promotion->promo_type !== 'PRICE_OVERRIDE'
                        ? 'MIX_MATCH'
                        : $promotion->promo_mode
                );
            $branchScopeType = $validated['branch_scope_type'] ?? $promotion->branch_scope_type ?? 'ALL';
            $warehouseScopeType = $validated['warehouse_scope_type'] ?? $promotion->warehouse_scope_type ?? 'ALL';

            $branchIds = array_key_exists('branch_ids', $validated)
                ? collect($validated['branch_ids'] ?? [])->unique()->values()->all()
                : $promotion->branches()->pluck('branches.id')->map(fn ($branchId) => (int) $branchId)->all();

            $warehouseIds = array_key_exists('warehouse_ids', $validated)
                ? collect($validated['warehouse_ids'] ?? [])->unique()->values()->all()
                : $promotion->warehouses()->pluck('warehouses.id')->map(fn ($warehouseId) => (int) $warehouseId)->all();

            if (empty($warehouseIds) && !empty($validated['warehouse_id'])) {
                $warehouseIds = [(int) $validated['warehouse_id']];
            }

            if ($branchScopeType === 'SELECTED' && empty($branchIds)) {
                throw ValidationException::withMessages([
                    'branch_ids' => 'branch_ids is required when branch_scope_type is SELECTED.',
                ]);
            }

            if ($warehouseScopeType === 'SELECTED' && empty($warehouseIds)) {
                throw ValidationException::withMessages([
                    'warehouse_ids' => 'warehouse_ids is required when warehouse_scope_type is SELECTED.',
                ]);
            }

            if ($targetPromoType === 'PRICE_OVERRIDE' && !isset($validated['override_price']) && is_null($promotion->override_price)) {
                throw ValidationException::withMessages([
                    'override_price' => 'override_price is required for price override promotions.',
                ]);
            }

            $totalUsed = $promotion->focAllocations()->sum('used_qty');

            $changesFocStock = array_key_exists('foc_allocations', $validated)
                || array_key_exists('branch_scope_type', $validated)
                || array_key_exists('branch_ids', $validated)
                || array_key_exists('warehouse_scope_type', $validated)
                || array_key_exists('warehouse_ids', $validated)
                || array_key_exists('warehouse_id', $validated)
                || (($promotion->promo_type !== 'FOC') && $targetPromoType === 'FOC')
                || (($promotion->promo_type === 'FOC') && $targetPromoType !== 'FOC');

            if ($totalUsed > 0 && ($changesFocStock || array_key_exists('tiers', $validated))) {
                throw ValidationException::withMessages([
                    'foc_allocations' => 'Cannot modify FOC tiers, allocation, branch scope, or warehouse scope after usage has started.'
                ]);
            }

            if (
                $targetPromoType === 'FOC'
                && $changesFocStock
                && !array_key_exists('foc_allocations', $validated)
                && !$promotion->focAllocations()->exists()
            ) {
                throw ValidationException::withMessages([
                    'foc_allocations' => 'foc_allocations is required when changing a promotion to FOC.',
                ]);
            }

            $tiers = array_key_exists('tiers', $validated)
                ? $this->normalizePromotionTiers($validated)
                : [];

            $conditionType = $validated['condition_type']
                ?? $validated['tiers'][0]['condition']['condition_type']
                ?? $validated['tiers'][0]['conditions'][0]['condition_type']
                ?? $promotion->condition_type
                ?? 'NONE';
            $targetStartAt = $validated['start_at'] ?? $promotion->start_at;
            $targetEndAt = $validated['end_at'] ?? $promotion->end_at;

            $promotion->update([
                'name' => $validated['name'] ?? $promotion->name,
                'description' => $validated['description'] ?? $promotion->description,
                'promo_type' => $targetPromoType,
                'condition_type' => $conditionType,
                'promo_mode' => $promoMode,
                'discount_type' => $validated['discount_type'] ?? $promotion->discount_type,
                'discount_value' => $validated['discount_value'] ?? $promotion->discount_value,
                'max_reward_value' => $validated['max_reward_value'] ?? $promotion->max_reward_value,
                'override_price' => $validated['override_price'] ?? $promotion->override_price,
                'branch_scope_type' => $branchScopeType,
                'warehouse_scope_type' => $warehouseScopeType,
                'status_id' => $this->promotionLifecycleStatusId($targetStartAt, $targetEndAt),
                'start_at' => $targetStartAt,
                'end_at' => $targetEndAt,
                'updated_by' => $validated['updated_by'] ?? $promotion->updated_by,
                'is_synced' => false,
                'synced_at' => null,
            ]);

            if (array_key_exists('products', $validated)) {
                $promotion->products()->sync($validated['products'] ?? []);
            }

            $targetBranchIds = $this->syncPromotionBranches($promotion, $branchScopeType, $branchIds);
            $targetWarehouseIds = $this->syncPromotionWarehouses(
                $promotion,
                $warehouseScopeType,
                $warehouseIds,
                $targetBranchIds
            );

            if ($promotion->promo_type !== 'FOC' && $promotion->focAllocations()->exists()) {
                $this->releasePromotionFocAllocations($promotion);
            }

            if (array_key_exists('tiers', $validated)) {
                $this->syncPromotionTiers($promotion, $tiers);
            }

            if ($promotion->promo_type === 'FOC' && ($changesFocStock || array_key_exists('foc_allocations', $validated))) {
                $focAllocations = array_key_exists('foc_allocations', $validated)
                    ? ($validated['foc_allocations'] ?? [])
                    : $promotion->focAllocations()
                        ->select('product_id', DB::raw('MAX(allocated_qty) as allocated_qty'))
                        ->groupBy('product_id')
                        ->get()
                        ->map(fn ($allocation) => [
                            'product_id' => (int) $allocation->product_id,
                            'allocated_qty' => (int) $allocation->allocated_qty,
                        ])
                        ->all();

                $this->syncPromotionFocAllocations($promotion, $focAllocations, $targetWarehouseIds);
            }

            DB::commit();

            return (new PromotionResource(
                $promotion->load([
                    'products',
                    'conditions.product',
                    'rewards.product',
                    'focAllocations.product',
                    'branches',
                    'warehouses',
                ])
            ))->response()->setStatusCode(200);

        } catch (ValidationException $e) {

            DB::rollBack();

            throw $e;

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Failed to update promotion',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'Internal Server Error'
            ], 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();

        try {
            $promotion = Promotion::with(['products', 'branches', 'warehouses', 'focAllocations'])
                ->lockForUpdate()
                ->findOrFail($id);

            if (!is_null($promotion->void_at)) {
                DB::commit();

                return response()->json([
                    'message' => 'Promotion is already voided.'
                ], 200);
            }

            if ($promotion->promo_type === 'FOC') {
                $this->closePromotionFocAllocations($promotion);
            }

            $voidStatus = Status::where('name', 'void')->lockForUpdate()->firstOrFail();

            $promotion->update([
                'status_id' => $voidStatus->id,
                'void_at'   => now(),
                'void_by'   => $request->void_by,
                'updated_by' => $request->void_by ?? $promotion->updated_by,
                'is_synced' => false,
                'synced_at' => null,
            ]);

            $promotion->products()->sync([]);
            $promotion->branches()->sync([]);
            $promotion->warehouses()->sync([]);

            DB::commit();

            return response()->json([
                'message' => 'Promotion voided successfully.'
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to void promotion',
                'error'   => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], 500);
        }
    }

    public function checkPrice(Request $request)
    {
        $request->validate([
            'branch_id' => 'nullable|exists:branches,id',
            'warehouse_id' => 'nullable|exists:warehouses,id',
            'cart' => 'nullable|array',
        ]);

        $cartItems = collect($request->cart ?? []);

        if ($cartItems->isEmpty()) {
            return response()->json([
                'items' => [],
                'order' => [
                    'total_discount' => 0,
                    'applied_promotions' => []
                ],
                'foc_items' => []
            ]);
        }

        $totalQty = $cartItems->sum('qty');
        $totalAmount = $cartItems->sum(fn($i) => $i['qty'] * $i['price']);
        $warehouseId = $request->warehouse_id ? (int) $request->warehouse_id : null;
        $branchId = $request->branch_id ? (int) $request->branch_id : null;

        if (!$branchId && $warehouseId) {
            $branchId = Branch::where('warehouse_id', $warehouseId)->value('id');
        }

        if ($branchId && !$warehouseId) {
            $warehouseId = Branch::where('id', $branchId)->value('warehouse_id');
        }

        if (!$branchId) {
            throw ValidationException::withMessages([
                'branch_id' => 'branch_id is required to check branch-scoped promotions.',
            ]);
        }

        if ($warehouseId) {
            $branchWarehouseId = Branch::where('id', $branchId)->value('warehouse_id');

            if ((int) $branchWarehouseId !== (int) $warehouseId) {
                throw ValidationException::withMessages([
                    'warehouse_id' => 'warehouse_id must belong to the selected branch.',
                ]);
            }
        }

        $this->refreshPromotionLifecycleStatuses();

        $promotions = Promotion::with(['products', 'conditions', 'rewards', 'focAllocations'])
            ->where('status_id', $this->statusIdByName('applied'))
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->whereHas('warehouses', fn ($warehouseQuery) => $warehouseQuery->where('warehouses.id', $warehouseId));
            })
            ->get();

        $productDiscounts = [];
        $orderDiscountAmount = 0;
        $orderPromotions = [];
        $freeItems = [];
        $effectiveUnitPrices = [];

        foreach ($cartItems as $item) {
            $effectiveUnitPrices[(int) $item['product_id']] = (float) ($item['original_price'] ?? $item['price']);
        }

        foreach ($promotions->where('promo_type', 'PRODUCT_DISCOUNT') as $promotion) {
            foreach ($cartItems as $item) {
                $productId = (int) $item['product_id'];
                $hasProduct = $promotion->products
                    ->where('id', $productId)
                    ->isNotEmpty();

                if (!$hasProduct) continue;

                $currentPrice = (float) ($effectiveUnitPrices[$productId] ?? $item['price']);
                $discount = $promotion->discount_type === 'PERCENT'
                    ? ($currentPrice * $promotion->discount_value) / 100
                    : $promotion->discount_value;
                $discount = min($discount, $currentPrice);
                $discountedPrice = max(0, $currentPrice - $discount);
                $effectiveUnitPrices[$productId] = $discountedPrice;

                $productDiscounts[] = [
                    'product_id' => $productId,
                    'promotion_id' => $promotion->id,
                    'promo_type' => 'PRODUCT_DISCOUNT',
                    'discount_amount' => $discount,
                    'discount_type' => $promotion->discount_type,
                    'discount_value' => $promotion->discount_value,
                    'discount_price' => $discountedPrice,
                ];
            }
        }

        foreach ($promotions->where('promo_type', 'PRICE_OVERRIDE') as $promotion) {
            $tiers = $promotion->conditions->groupBy('tier');
            $tiers = $promotion->promo_mode === 'TIER'
                ? $tiers->sortKeysDesc()
                : $tiers->sortKeys();

            foreach ($tiers as $tier => $conditions) {
                $overrideItems = $this->getPriceOverrideCartItems($cartItems, $promotion, $conditions);
                $overrideQty = $overrideItems->sum('qty');
                $overrideAmount = $overrideItems->sum(
                    fn ($item) => $item['qty'] * ($item['original_price'] ?? $item['price'])
                );

                [$isEligible] = $this->evaluatePromotionConditions(
                    $overrideItems,
                    $promotion,
                    $conditions,
                    $overrideAmount,
                    $overrideQty
                );

                if (!$isEligible || is_null($promotion->override_price)) continue;

                $overrideUnitPrice = $this->getPriceOverrideUnitPrice($promotion, $conditions);

                Log::info("Evaluating PRICE_OVERRIDE promotion ID {$promotion->id} for tier {$tier}", [
                    'override_unit_price' => $overrideUnitPrice,
                    'override_items' => $overrideItems,
                    'override_qty' => $overrideQty,
                    'override_amount' => $overrideAmount,
                ]);

                foreach ($overrideItems as $item) {
                    $productId = (int) $item['product_id'];
                    $currentPrice = (float) ($effectiveUnitPrices[$productId] ?? ($item['original_price'] ?? $item['price']));
                    $discountedPrice = min($currentPrice, $overrideUnitPrice);
                    $discount = max(0, $currentPrice - $discountedPrice);
                    $isAlreadyOverridePrice = abs($currentPrice - $overrideUnitPrice) < 0.01;

                    if ($discount <= 0 && !$isAlreadyOverridePrice) continue;

                    $effectiveUnitPrices[$productId] = $discountedPrice;

                    $productDiscounts[] = [
                        'product_id' => $productId,
                        'promotion_id' => $promotion->id,
                        'promo_type' => 'PRICE_OVERRIDE',
                        'tier' => (int) $tier,
                        'discount_amount' => $discount,
                        'discount_type' => 'AMOUNT',
                        'discount_value' => $discount,
                        'override_price' => (float) $promotion->override_price,
                        'discount_price' => $discountedPrice,
                    ];
                }

                if ($promotion->promo_mode === 'TIER') break;
            }
        }

        $discountedCartItems = $cartItems->map(function ($item) use ($effectiveUnitPrices) {
            $productId = (int) $item['product_id'];
            $item['original_price'] = $item['price'];
            $item['price'] = (float) ($effectiveUnitPrices[$productId] ?? $item['price']);

            return $item;
        });

        $discountedProductTotal = $discountedCartItems->sum(fn($i) => $i['qty'] * $i['price']);

        foreach ($promotions->where('promo_type', 'ORDER_DISCOUNT') as $promotion) {
            $tiers = $promotion->conditions->groupBy('tier');
            $tiers = $promotion->promo_mode === 'TIER'
                ? $tiers->sortKeysDesc()
                : $tiers->sortKeys();

            foreach ($tiers as $tier => $conditions) {
                [$isEligible, $multiplier] = $this->evaluatePromotionConditions(
                    $discountedCartItems,
                    $promotion,
                    $conditions,
                    $discountedProductTotal,
                    $totalQty
                );

                if (!$isEligible) continue;

                $reward = $promotion->rewards
                    ->where('tier', $tier)
                    ->first();

                if (!$reward) continue;

                $discount = $reward->reward_value;

                if ($promotion->promo_mode === 'MULTIPLIER') {
                    $discount *= $multiplier;
                }

                if (!is_null($promotion->max_reward_value)) {
                    $discount = min($discount, $promotion->max_reward_value);
                }

                $discount = min($discount, max(0, $discountedProductTotal - $orderDiscountAmount));
                $orderDiscountAmount += $discount;

                $orderPromotions[] = [
                    'promotion_id' => $promotion->id,
                    'tier' => $tier,
                    'discount' => $discount
                ];

                if ($promotion->promo_mode === 'TIER') break;
            }
        }

        $finalOrderAmount = max(0, $discountedProductTotal - $orderDiscountAmount);

        $focAvailabilityQuery = Inventory::query()
            ->select('product_id', DB::raw('SUM(foc_qty) as available_foc_qty'))
            ->groupBy('product_id');

        if (!empty($warehouseId)) {
            $focAvailabilityQuery->where('warehouse_id', $warehouseId);
        }

        $focAvailability = $focAvailabilityQuery
            ->pluck('available_foc_qty', 'product_id')
            ->map(fn ($qty) => (int) $qty)
            ->toArray();

        $focReserved = [];
        $focReservedByPool = [];

        foreach ($promotions->where('promo_type', 'FOC') as $promotion) {
            $tiers = $promotion->conditions->groupBy('tier');
            $tiers = $promotion->promo_mode === 'TIER'
                ? $tiers->sortKeysDesc()
                : $tiers->sortKeys();

            foreach ($tiers as $tier => $conditions) {
                [$isEligible, $multiplier] = $this->evaluatePromotionConditions(
                    $discountedCartItems,
                    $promotion,
                    $conditions,
                    $finalOrderAmount,
                    $totalQty
                );

                if (!$isEligible) continue;

                $tierRewards = $promotion->rewards->where('tier', $tier);
                $totalFreeQtyForPromo = collect($freeItems)
                    ->where('promotion_id', $promotion->id)
                    ->sum('qty');

                foreach ($tierRewards as $reward) {
                    $productId = (int) $reward->product_id;
                    $qty = $reward->reward_qty;

                    if ($promotion->promo_mode === 'MULTIPLIER') {
                        $qty *= $multiplier;
                    }

                    if (!is_null($promotion->max_reward_value)) {
                        $remainingQty = $promotion->max_reward_value - $totalFreeQtyForPromo;

                        if ($remainingQty <= 0) break;

                        $qty = min($qty, $remainingQty);
                    }

                    $matchingPools = $promotion->focAllocations
                        ->where('product_id', $productId);

                    if (!empty($warehouseId)) {
                        $matchingPools = $matchingPools
                            ->where('allocated_warehouse_id', (int) $warehouseId);
                    }

                    if ($matchingPools->isEmpty()) continue;

                    $allocatedQty = (int) $matchingPools->sum('allocated_qty');
                    $usedQty = (int) $matchingPools->sum('used_qty');
                    $poolKey = $promotion->id . ':' . $productId . ':' . ((int) ($warehouseId ?? 0));
                    $reservedPoolQty = (int) ($focReservedByPool[$poolKey] ?? 0);
                    $remainingAllocatableQty = max(0, $allocatedQty - $usedQty - $reservedPoolQty);

                    if ($remainingAllocatableQty <= 0) continue;

                    $qty = min($qty, $remainingAllocatableQty);
                    $availableQty = ($focAvailability[$productId] ?? 0)
                        - ($focReserved[$productId] ?? 0);

                    if ($availableQty <= 0) continue;

                    $qty = min($qty, $availableQty);

                    if ($qty <= 0) continue;

                    $freeItems[] = [
                        'product_id' => $productId,
                        'qty' => $qty,
                        'reward_id' => (int) $reward->id,
                        'promotion_id' => $promotion->id
                    ];

                    $focReserved[$productId] = ($focReserved[$productId] ?? 0) + $qty;
                    $focReservedByPool[$poolKey] = ($focReservedByPool[$poolKey] ?? 0) + $qty;
                    $totalFreeQtyForPromo += $qty;
                }

                if ($promotion->promo_mode === 'TIER') break;
            }
        }

        return response()->json([
            'items' => $productDiscounts,
            'order' => [
                'total_discount' => $orderDiscountAmount,
                'subtotal_after_product_discounts' => $discountedProductTotal,
                'final_amount' => $finalOrderAmount,
                'applied_promotions' => $orderPromotions
            ],
            'foc_items' => $freeItems
        ]);
    }

    private function getConditionCartItems($cartItems, Promotion $promotion, $condition)
    {
        if (!empty($condition->product_id)) {
            return $cartItems->where('product_id', $condition->product_id);
        }

        $promotionProductIds = $promotion->products
            ->pluck('id')
            ->map(fn ($productId) => (int) $productId)
            ->all();

        if (!empty($promotionProductIds)) {
            return $cartItems->filter(
                fn ($item) => in_array((int) $item['product_id'], $promotionProductIds, true)
            );
        }

        return $cartItems;
    }

    private function evaluatePromotionConditions($cartItems, Promotion $promotion, $conditions, float $orderAmount, $orderQty): array
    {
        $isEligible = true;
        $multipliers = [];

        foreach ($conditions as $condition) {
            $eligible = false;
            $multiplier = null;
            $targetValue = (float) ($condition->target_value ?? 0);

            switch ($condition->condition_type) {
                case 'ITEM_QTY':
                    $qty = $this->getConditionCartItems($cartItems, $promotion, $condition)
                        ->sum('qty');

                    $eligible = $this->comparePromotionValue($qty, $condition);
                    $multiplier = $targetValue > 0 ? floor($qty / $targetValue) : 1;
                    break;

                case 'ITEM_AMOUNT':
                    $amount = $this->getConditionCartItems($cartItems, $promotion, $condition)
                        ->sum(fn ($i) => $i['qty'] * $i['price']);

                    $eligible = $this->comparePromotionValue($amount, $condition);
                    $multiplier = $targetValue > 0 ? floor($amount / $targetValue) : 1;
                    break;

                case 'ORDER_AMOUNT':
                    $eligible = $this->comparePromotionValue($orderAmount, $condition);
                    $multiplier = $targetValue > 0 ? floor($orderAmount / $targetValue) : 1;
                    break;

                case 'ORDER_QTY':
                    $eligible = $this->comparePromotionValue($orderQty, $condition);
                    $multiplier = $targetValue > 0 ? floor($orderQty / $targetValue) : 1;
                    break;
            }

            if (!$eligible) {
                $isEligible = false;
                break;
            }

            if (!is_null($multiplier)) {
                $multipliers[] = max(1, (int) $multiplier);
            }
        }

        return [
            $isEligible,
            count($multipliers) ? min($multipliers) : 1,
        ];
    }

    private function comparePromotionValue($actualValue, $condition): bool
    {
        $actualValue = (float) $actualValue;
        $targetValue = (float) ($condition->target_value ?? 0);
        $targetValueTo = (float) ($condition->target_value_to ?? 0);
        $operator = strtoupper(trim((string) ($condition->operator ?? '>=')));

        return match ($operator) {
            '>', 'GT' => $actualValue > $targetValue,
            '<', 'LT' => $actualValue < $targetValue,
            '<=', '=<', 'LTE' => $actualValue <= $targetValue,
            '=', '==', 'EQ' => $actualValue == $targetValue,
            'BETWEEN' => $actualValue >= $targetValue && $actualValue <= $targetValueTo,
            default => $actualValue >= $targetValue,
        };
    }

    private function getPriceOverrideCartItems($cartItems, Promotion $promotion, $conditions)
    {
        $promotionProductIds = $promotion->products
            ->pluck('id')
            ->map(fn ($productId) => (int) $productId)
            ->all();
        
        Log::info('Price Override Promotion Product IDs', [
            'promotion_product_ids' => $promotionProductIds
        ]);

        if (!empty($promotionProductIds)) {
            
            $returnCartItems = $cartItems->filter(
                fn ($item) => in_array((int) $item['product_id'], $promotionProductIds, true)
            );

            Log::info('Price Override Cart Items after Promotion Product ID filter', [
                'cart_items' => $returnCartItems->values()->all()
            ]);

            return $returnCartItems;
        }

        $conditionProductIds = $conditions
            ->pluck('product_id')
            ->filter()
            ->map(fn ($productId) => (int) $productId)
            ->unique()
            ->values()
            ->all();
        
        Log::info('Price Override Condition Product IDs', [
            'condition_product_ids' => $conditionProductIds
        ]);

        if (!empty($conditionProductIds)) {
            return $cartItems->filter(
                fn ($item) => in_array((int) $item['product_id'], $conditionProductIds, true)
            );
        }

        return $cartItems;
    }

    private function getPriceOverrideUnitPrice(Promotion $promotion, $conditions): float
    {
        $quantityTarget = $conditions
            ->whereIn('condition_type', ['ITEM_QTY', 'ORDER_QTY'])
            ->pluck('target_value')
            ->filter(fn ($targetValue) => (float) $targetValue > 0)
            ->map(fn ($targetValue) => (float) $targetValue)
            ->min();

        if (!$quantityTarget) {
            return (float) $promotion->override_price;
        }

        return round((float) $promotion->override_price / $quantityTarget, 2);
    }

    private function normalizePromotionTiers(array $validated): array
    {
        $tiers = [];

        foreach ($validated['tiers'] ?? [] as $tierData) {
            $conditions = [];

            if (!empty($tierData['conditions'])) {
                $conditions = $tierData['conditions'];
            } elseif (!empty($tierData['condition'])) {
                $conditions[] = $tierData['condition'];
            }

            $rewards = [];

            if (!empty($tierData['rewards'])) {
                $rewards = $tierData['rewards'];
            } elseif (!empty($tierData['reward'])) {
                $rewards[] = $tierData['reward'];
            }

            $tiers[] = [
                'conditions' => $conditions,
                'rewards' => $rewards,
            ];
        }

        if (empty($tiers) && !empty($validated['condition_type']) && $validated['condition_type'] !== 'NONE') {
            $reward = [];

            if (array_key_exists('discount_value', $validated)) {
                $reward['reward_value'] = $validated['discount_value'];
            }

            $tiers[] = [
                'conditions' => [[
                    'condition_type' => $validated['condition_type'],
                    'product_id' => $validated['product_id'] ?? null,
                    'target_value' => $validated['target_value'] ?? 0,
                    'operator' => '>=',
                ]],
                'rewards' => empty($reward) ? [] : [$reward],
            ];
        }

        foreach ($tiers as $tierIndex => $tier) {
            if (empty($tier['conditions'])) {
                throw ValidationException::withMessages([
                    "tiers.{$tierIndex}.conditions" => 'At least one condition is required for each tier.',
                ]);
            }

            foreach ($tier['conditions'] as $conditionIndex => $condition) {
                if (
                    empty($condition['condition_type'])
                    || !array_key_exists('target_value', $condition)
                ) {
                    throw ValidationException::withMessages([
                        "tiers.{$tierIndex}.conditions.{$conditionIndex}" => 'condition_type and target_value are required.',
                    ]);
                }

                if (
                    in_array($condition['condition_type'], ['ITEM_QTY', 'ITEM_AMOUNT'], true)
                    && empty($condition['product_id'])
                ) {
                    throw ValidationException::withMessages([
                        "tiers.{$tierIndex}.conditions.{$conditionIndex}.product_id" => 'product_id is required for item-level conditions.',
                    ]);
                }
            }
        }

        return $tiers;
    }

    private function syncPromotionBranches(Promotion $promotion, string $scopeType, array $branchIds): array
    {
        $targetBranchIds = $scopeType === 'SELECTED'
            ? $branchIds
            : Branch::query()
                ->orderBy('id')
                ->pluck('id')
                ->all();

        $targetBranchIds = collect($targetBranchIds)
            ->map(fn ($branchId) => (int) $branchId)
            ->filter(fn ($branchId) => $branchId > 0)
            ->unique()
            ->values()
            ->all();

        if (empty($targetBranchIds)) {
            throw ValidationException::withMessages([
                'branch_ids' => 'At least one branch is required for promotion branch scope.',
            ]);
        }

        $incomingBranchIds = [];

        foreach ($targetBranchIds as $branchId) {
            $promotionBranch = PromotionBranch::firstOrCreate(
                [
                    'promotion_id' => $promotion->id,
                    'branch_id' => $branchId,
                ]
            );

            $incomingBranchIds[] = $promotionBranch->id;
        }

        PromotionBranch::where('promotion_id', $promotion->id)
            ->whereNotIn('id', $incomingBranchIds)
            ->delete();

        return $targetBranchIds;
    }

    private function syncPromotionWarehouses(Promotion $promotion, string $scopeType, array $warehouseIds, array $branchIds): array
    {
        $branchWarehouseIds = Branch::query()
            ->whereIn('id', $branchIds)
            ->whereNotNull('warehouse_id')
            ->orderBy('warehouse_id')
            ->pluck('warehouse_id')
            ->map(fn ($warehouseId) => (int) $warehouseId)
            ->unique()
            ->values()
            ->all();

        if (empty($branchWarehouseIds)) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'The selected promotion branches do not have related warehouses.',
            ]);
        }

        $targetWarehouseIds = $scopeType === 'SELECTED'
            ? $warehouseIds
            : $branchWarehouseIds;

        $targetWarehouseIds = collect($targetWarehouseIds)
            ->map(fn ($warehouseId) => (int) $warehouseId)
            ->filter(fn ($warehouseId) => $warehouseId > 0)
            ->unique()
            ->values()
            ->all();

        $invalidWarehouseIds = array_values(array_diff($targetWarehouseIds, $branchWarehouseIds));

        if (!empty($invalidWarehouseIds)) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Selected warehouses must be related to the selected promotion branches. Invalid warehouse IDs: ' . implode(', ', $invalidWarehouseIds),
            ]);
        }

        if (empty($targetWarehouseIds)) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'At least one warehouse is required for promotion warehouse scope.',
            ]);
        }

        $incomingWarehouseIds = [];

        foreach ($targetWarehouseIds as $warehouseId) {
            $promotionWarehouse = PromotionWarehouse::firstOrCreate(
                [
                    'promotion_id' => $promotion->id,
                    'warehouse_id' => $warehouseId,
                ]
            );

            $incomingWarehouseIds[] = $promotionWarehouse->id;
        }

        PromotionWarehouse::where('promotion_id', $promotion->id)
            ->whereNotIn('id', $incomingWarehouseIds)
            ->delete();

        return $targetWarehouseIds;
    }

    private function statusIdByName(string $name): int
    {
        $statusId = Status::whereRaw('LOWER(name) = ?', [strtolower($name)])
            ->value('id');

        if (!$statusId) {
            throw new \RuntimeException("Status '{$name}' is required.");
        }

        return (int) $statusId;
    }

    private function refreshPromotionLifecycleStatuses(): void
    {
        $now = now();
        $activeStatusId = $this->statusIdByName('active');
        $appliedStatusId = $this->statusIdByName('applied');
        $inactiveStatusId = $this->statusIdByName('inactive');

        DB::transaction(function () use ($now, $activeStatusId, $appliedStatusId, $inactiveStatusId) {
            Promotion::whereNull('void_at')
                ->where('start_at', '>', $now)
                ->where('status_id', '!=', $activeStatusId)
                ->update(['status_id' => $activeStatusId]);

            Promotion::whereNull('void_at')
                ->where('start_at', '<=', $now)
                ->where('end_at', '>=', $now)
                ->where('status_id', '!=', $appliedStatusId)
                ->update(['status_id' => $appliedStatusId]);

            $expiredPromoIds = Promotion::whereNull('void_at')
                ->where('end_at', '<', $now)
                ->where('status_id', '!=', $inactiveStatusId)
                ->lockForUpdate()
                ->pluck('id');

            if ($expiredPromoIds->isNotEmpty()) {
                $expiredPromotions = Promotion::with('focAllocations')
                    ->whereIn('id', $expiredPromoIds)
                    ->get();

                foreach ($expiredPromotions as $promotion) {
                    if ($promotion->promo_type !== 'FOC') {
                        continue;
                    }

                    foreach ($promotion->focAllocations as $allocation) {
                        $remaining = (int) $allocation->allocated_qty - (int) $allocation->used_qty;

                        if ($remaining > 0 && $allocation->allocated_warehouse_id) {
                            $this->releaseFocStock(
                                (int) $allocation->product_id,
                                (int) $allocation->allocated_warehouse_id,
                                $remaining
                            );
                        }
                    }
                }
            }

            Promotion::whereNull('void_at')
                ->where('end_at', '<', $now)
                ->where('status_id', '!=', $inactiveStatusId)
                ->update(['status_id' => $inactiveStatusId]);

            Log::info("expiredPromo IDs: " . $expiredPromoIds->implode(','));
        });
    }

    private function promotionLifecycleStatusId($startAt, $endAt): int
    {
        $now = now();
        $startAt = \Carbon\Carbon::parse($startAt);
        $endAt = \Carbon\Carbon::parse($endAt);

        if ($endAt->lt($now)) {
            return $this->statusIdByName('inactive');
        }

        if ($startAt->gt($now)) {
            return $this->statusIdByName('active');
        }

        return $this->statusIdByName('applied');
    }

    private function promotionReservableStatusIds(): array
    {
        return [
            $this->statusIdByName('active'),
            $this->statusIdByName('applied'),
        ];
    }

    private function syncPromotionTiers(Promotion $promotion, array $tiers): void
    {
        $incomingConditionIds = [];
        $incomingRewardIds = [];

        foreach ($tiers as $index => $tierData) {
            $tier = $index + 1;
            $conditions = $tierData['conditions'] ?? [];

            foreach ($conditions as $conditionIndex => $condition) {
                $conditionPayload = [
                    'product_id' => $condition['product_id'] ?? null,
                    'group_no' => $condition['group_no'] ?? ($conditionIndex + 1),
                    'condition_type' => $condition['condition_type'],
                    'operator' => $condition['operator'] ?? '>=',
                    'target_value' => $condition['target_value'],
                    'target_value_to' => $condition['target_value_to'] ?? null,
                    'tier' => $tier,
                ];

                if (!empty($condition['id'])) {
                    $promotionCondition = PromotionCondition::where('promotion_id', $promotion->id)
                        ->findOrFail($condition['id']);

                    $promotionCondition->update($conditionPayload);
                } else {
                    $promotionCondition = $promotion->conditions()->create($conditionPayload);
                }

                $incomingConditionIds[] = $promotionCondition->id;
            }

            foreach ($tierData['rewards'] ?? [] as $reward) {
                $rewardPayload = [
                    'reward_type' => $promotion->promo_type === 'FOC' ? 'FREE_PRODUCT' : 'DISCOUNT',
                    'product_id' => $reward['product_id'] ?? null,
                    'reward_qty' => $reward['reward_qty'] ?? null,
                    'reward_value' => $reward['reward_value'] ?? null,
                    'tier' => $tier,
                ];

                if (!empty($reward['id'])) {
                    $promotionReward = PromotionReward::where('promotion_id', $promotion->id)
                        ->findOrFail($reward['id']);

                    $promotionReward->update($rewardPayload);
                } else {
                    $promotionReward = $promotion->rewards()->create($rewardPayload);
                }

                $incomingRewardIds[] = $promotionReward->id;
            }
        }

        $promotion->conditions()
            ->whereNotIn('id', $incomingConditionIds)
            ->delete();

        $promotion->rewards()
            ->whereNotIn('id', $incomingRewardIds)
            ->delete();
    }

    private function releasePromotionFocAllocations(Promotion $promotion): void
    {
        $allocations = $promotion->focAllocations()
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            $remaining = (int) $allocation->allocated_qty - (int) $allocation->used_qty;

            if ($remaining > 0 && !empty($allocation->allocated_warehouse_id)) {
                $this->releaseFocStock(
                    (int) $allocation->product_id,
                    (int) $allocation->allocated_warehouse_id,
                    $remaining
                );
            }

            $allocation->delete();
        }
    }

    private function closePromotionFocAllocations(Promotion $promotion): void
    {
        $allocations = $promotion->focAllocations()
            ->lockForUpdate()
            ->get();

        foreach ($allocations as $allocation) {
            $usedQty = (int) $allocation->used_qty;
            $remaining = max(0, (int) $allocation->allocated_qty - $usedQty);

            if ($remaining > 0 && !empty($allocation->allocated_warehouse_id)) {
                $this->releaseFocStock(
                    (int) $allocation->product_id,
                    (int) $allocation->allocated_warehouse_id,
                    $remaining
                );
            }

            $allocation->update([
                'allocated_qty' => $usedQty,
            ]);
        }
    }

    private function createPromotionFocAllocations(Promotion $promotion, array $focAllocations, array $warehouseIds): void
    {
        foreach ($focAllocations as $allocation) {
            $productId = (int) $allocation['product_id'];
            $qty = (int) $allocation['allocated_qty'];

            $exists = PromotionFocAllocation::where('product_id', $productId)
                ->where('promotion_id', '!=', $promotion->id)
                ->whereHas('promotion', fn($q) => $q
                    ->whereNull('void_at')
                    ->whereIn('status_id', $this->promotionReservableStatusIds())
                )
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'foc_allocations' => "Product {$productId} already allocated in another active FOC promotion.",
                ]);
            }

            foreach ($warehouseIds as $warehouseId) {
                $this->allocateFocStock($productId, (int) $warehouseId, $qty);

                PromotionFocAllocation::create([
                    'promotion_id' => $promotion->id,
                    'product_id' => $productId,
                    'allocated_qty' => $qty,
                    'used_qty' => 0,
                    'allocated_warehouse_id' => (int) $warehouseId,
                ]);
            }
        }
    }

    private function syncPromotionFocAllocations(Promotion $promotion, array $focAllocations, array $warehouseIds): void
    {
        $incomingAllocationIds = [];

        foreach ($focAllocations as $allocation) {
            $productId = (int) $allocation['product_id'];
            $qty = (int) $allocation['allocated_qty'];

            $exists = PromotionFocAllocation::where('product_id', $productId)
                ->where('promotion_id', '!=', $promotion->id)
                ->whereHas('promotion', fn($q) => $q
                    ->whereNull('void_at')
                    ->whereIn('status_id', $this->promotionReservableStatusIds())
                )
                ->lockForUpdate()
                ->exists();

            if ($exists) {
                throw ValidationException::withMessages([
                    'foc_allocations' => "Product {$productId} already allocated in another active FOC promotion.",
                ]);
            }

            if (!empty($allocation['id'])) {
                $existingAllocation = PromotionFocAllocation::where('promotion_id', $promotion->id)
                    ->lockForUpdate()
                    ->findOrFail($allocation['id']);

                $warehouseId = (int) ($allocation['allocated_warehouse_id'] ?? $existingAllocation->allocated_warehouse_id);

                if (!in_array($warehouseId, $warehouseIds, true)) {
                    throw ValidationException::withMessages([
                        'foc_allocations' => "FOC allocation warehouse {$warehouseId} is not allowed by this promotion warehouse scope.",
                    ]);
                }

                $oldRemaining = (int) $existingAllocation->allocated_qty - (int) $existingAllocation->used_qty;
                $samePool = (int) $existingAllocation->product_id === $productId
                    && (int) $existingAllocation->allocated_warehouse_id === $warehouseId;

                if (!$samePool) {
                    if ($oldRemaining > 0) {
                        $this->releaseFocStock(
                            (int) $existingAllocation->product_id,
                            (int) $existingAllocation->allocated_warehouse_id,
                            $oldRemaining
                        );
                    }

                    $this->allocateFocStock($productId, $warehouseId, $qty);
                } else {
                    $difference = $qty - (int) $existingAllocation->allocated_qty;

                    if ($difference > 0) {
                        $this->allocateFocStock($productId, $warehouseId, $difference);
                    } elseif ($difference < 0) {
                        $releaseQty = min(abs($difference), $oldRemaining);

                        if ($releaseQty > 0) {
                            $this->releaseFocStock($productId, $warehouseId, $releaseQty);
                        }
                    }
                }

                $existingAllocation->update([
                    'product_id' => $productId,
                    'allocated_qty' => $qty,
                    'allocated_warehouse_id' => $warehouseId,
                ]);

                $incomingAllocationIds[] = $existingAllocation->id;

                continue;
            }

            $targetWarehouseIds = !empty($allocation['allocated_warehouse_id'])
                ? [(int) $allocation['allocated_warehouse_id']]
                : $warehouseIds;

            foreach ($targetWarehouseIds as $warehouseId) {
                $warehouseId = (int) $warehouseId;

                if (!in_array($warehouseId, $warehouseIds, true)) {
                    throw ValidationException::withMessages([
                        'foc_allocations' => "FOC allocation warehouse {$warehouseId} is not allowed by this promotion warehouse scope.",
                    ]);
                }

                $existingAllocation = PromotionFocAllocation::where('promotion_id', $promotion->id)
                    ->where('product_id', $productId)
                    ->where('allocated_warehouse_id', $warehouseId)
                    ->lockForUpdate()
                    ->first();

                if ($existingAllocation) {
                    $oldRemaining = (int) $existingAllocation->allocated_qty - (int) $existingAllocation->used_qty;
                    $difference = $qty - (int) $existingAllocation->allocated_qty;

                    if ($difference > 0) {
                        $this->allocateFocStock($productId, $warehouseId, $difference);
                    } elseif ($difference < 0) {
                        $releaseQty = min(abs($difference), $oldRemaining);

                        if ($releaseQty > 0) {
                            $this->releaseFocStock($productId, $warehouseId, $releaseQty);
                        }
                    }

                    $existingAllocation->update([
                        'allocated_qty' => $qty,
                    ]);

                    $incomingAllocationIds[] = $existingAllocation->id;

                    continue;
                }

                $this->allocateFocStock($productId, $warehouseId, $qty);

                $newAllocation = PromotionFocAllocation::create([
                    'promotion_id' => $promotion->id,
                    'product_id' => $productId,
                    'allocated_qty' => $qty,
                    'used_qty' => 0,
                    'allocated_warehouse_id' => $warehouseId,
                ]);

                $incomingAllocationIds[] = $newAllocation->id;
            }
        }

        $allocationsToDelete = $promotion->focAllocations()
            ->whereNotIn('id', $incomingAllocationIds)
            ->lockForUpdate()
            ->get();

        foreach ($allocationsToDelete as $allocation) {
            $remaining = (int) $allocation->allocated_qty - (int) $allocation->used_qty;

            if ($remaining > 0 && !empty($allocation->allocated_warehouse_id)) {
                $this->releaseFocStock(
                    (int) $allocation->product_id,
                    (int) $allocation->allocated_warehouse_id,
                    $remaining
                );
            }

            $allocation->delete();
        }
    }

    private function allocateFocStock(int $productId, int $warehouseId, int $qty): void
    {
        Log::info("Attempting to allocate FOC stock", [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'requested_qty' => $qty
        ]);
        $inventories = Inventory::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('qty', '>', 0)
            ->lockForUpdate()
            ->orderBy('expired_date')
            ->get();

        $available = $inventories->sum('qty');

        if ($available < $qty) {
            throw ValidationException::withMessages([
                'tiers' => "Insufficient stock for product {$productId}"
            ]);
        }

        $remaining = $qty;

        foreach ($inventories as $inv) {
            if ($remaining <= 0) break;

            $move = min($remaining, $inv->qty);

            $inv->update([
                'qty' => $inv->qty - $move,
                'foc_qty' => $inv->foc_qty + $move,
            ]);

            $remaining -= $move;
        }
    }

    private function releaseFocStock(int $productId, int $warehouseId, int $qty): void
    {
        if ($qty <= 0) {
            return;
        }

        $inventories = Inventory::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->where('foc_qty', '>', 0)
            ->orderByRaw('expired_date IS NULL')
            ->orderBy('expired_date')
            ->orderBy('created_at')
            ->lockForUpdate()
            ->get();

        $remaining = $qty;

        foreach ($inventories as $inventory) {
            if ($remaining <= 0) {
                break;
            }

            $moveQty = min($remaining, (int) $inventory->foc_qty);

            $inventory->decrement('foc_qty', $moveQty);
            $inventory->increment('qty', $moveQty);

            $remaining -= $moveQty;
        }
    }

    private function extractFocAllocationByProduct(array $tiers): array
    {
        $allocationMap = [];

        Log::info('Extracting FOC allocation by product from tiers', ['tiers' => $tiers]);

        foreach ($tiers as $tierData) {
            $tierRewards = $tierData['rewards'] ?? [];

            foreach ($tierRewards as $reward) {
                $productId = (int) ($reward['product_id'] ?? 0);
                $qty = (int) ($reward['foc_stock_qty'] ?? 0);

                if ($productId <= 0 || $qty <= 0) {
                    continue;
                }

                // SUM instead of enforce equal
                $allocationMap[$productId] = ($allocationMap[$productId] ?? 0) + $qty;
            }
        }

        Log::info("Extracted FOC allocation map", ['allocation_map' => $allocationMap]);

        return $allocationMap;
    }
    
}
