<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Branch;
use App\Models\Inventory;
use App\Models\ProductUnit;
use App\Models\Promotion;
use App\Models\PromotionBranch;
use App\Models\PromotionCondition;
use App\Models\PromotionFocAllocation;
use App\Models\PromotionReward;
use App\Models\PromotionWarehouse;
use App\Models\Status;
use App\Services\SellingPriceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            'conditions.productUnit.unit',
            'conditions.unit',
            'rewards.productUnit.unit',
            'rewards.unit',
            'focAllocations.productUnit.unit',
            'focAllocations.unit',
            'focAllocations.branch',
            'focAllocations.warehouse',
        ])->latest()->get();

        return PromotionResource::collection($promotions);
    }

    public function store(Request $request)
    {
        try {
            $this->normalizePromotionProductsInput($request);

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
                'promotion_products' => 'nullable|array',
                'promotion_products.*.product_id' => 'required_with:promotion_products|exists:products,id',
                'promotion_products.*.product_unit_id' => 'nullable|exists:product_units,id',
                'promotion_products.*.unit_id' => 'nullable|exists:units,id',
                'promotion_products.*.max_qty_per_sales_order' => 'nullable|integer|min:1',
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
                'tiers.*.condition.product_unit_id' => 'nullable|exists:product_units,id',
                'tiers.*.condition.unit_id' => 'nullable|exists:units,id',
                'tiers.*.condition.unit_name' => 'nullable|string|max:255',
                'tiers.*.condition.conversion_to_base' => 'nullable|numeric|gt:0',
                'tiers.*.condition.operator' => 'nullable|string|max:10',
                'tiers.*.condition.target_value_to' => 'nullable|numeric|min:0',
                'tiers.*.conditions' => 'nullable|array',
                'tiers.*.conditions.*.condition_type' => 'required_with:tiers.*.conditions|in:ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
                'tiers.*.conditions.*.target_value' => 'required_with:tiers.*.conditions|numeric|min:0',
                'tiers.*.conditions.*.product_id' => 'nullable|exists:products,id',
                'tiers.*.conditions.*.product_unit_id' => 'nullable|exists:product_units,id',
                'tiers.*.conditions.*.unit_id' => 'nullable|exists:units,id',
                'tiers.*.conditions.*.unit_name' => 'nullable|string|max:255',
                'tiers.*.conditions.*.conversion_to_base' => 'nullable|numeric|gt:0',
                'tiers.*.conditions.*.operator' => 'nullable|string|max:10',
                'tiers.*.conditions.*.target_value_to' => 'nullable|numeric|min:0',

                'tiers.*.reward' => 'nullable|array',
                'tiers.*.reward.reward_value' => 'nullable|numeric|min:0',
                'tiers.*.reward.reward_qty' => 'nullable|integer|min:1',
                'tiers.*.reward.product_id' => 'nullable|exists:products,id',
                'tiers.*.reward.product_unit_id' => 'nullable|exists:product_units,id',
                'tiers.*.reward.unit_id' => 'nullable|exists:units,id',
                'tiers.*.reward.unit_name' => 'nullable|string|max:255',
                'tiers.*.reward.conversion_to_base' => 'nullable|numeric|gt:0',
                'tiers.*.reward.override_price' => 'nullable|numeric|min:0',
                'tiers.*.rewards' => 'nullable|array',
                'tiers.*.rewards.*.product_id' => 'nullable|exists:products,id',
                'tiers.*.rewards.*.product_unit_id' => 'nullable|exists:product_units,id',
                'tiers.*.rewards.*.unit_id' => 'nullable|exists:units,id',
                'tiers.*.rewards.*.unit_name' => 'nullable|string|max:255',
                'tiers.*.rewards.*.conversion_to_base' => 'nullable|numeric|gt:0',
                'tiers.*.rewards.*.reward_qty' => 'nullable|integer|min:1',
                'tiers.*.rewards.*.reward_value' => 'nullable|numeric|min:0',
                'tiers.*.rewards.*.override_price' => 'nullable|numeric|min:0',

                'foc_allocations' => 'required_if:promo_type,FOC|array|min:1',
                'foc_allocations.*.branch_id' => 'required_with:foc_allocations|integer|exists:branches,id',
                'foc_allocations.*.product_id' => 'required_with:foc_allocations|exists:products,id',
                'foc_allocations.*.product_unit_id' => 'required_with:foc_allocations|exists:product_units,id',
                'foc_allocations.*.unit_id' => 'nullable|exists:units,id',
                'foc_allocations.*.unit_name' => 'nullable|string|max:255',
                'foc_allocations.*.unit_quantity' => 'nullable|numeric|min:0',
                'foc_allocations.*.base_quantity' => 'nullable|numeric|min:0',
                'foc_allocations.*.conversion_to_base' => 'nullable|numeric|gt:0',
                'foc_allocations.*.allocated_warehouse_id' => 'prohibited',
                'foc_allocations.*.allocated_qty' => 'required_with:foc_allocations|integer|min:1',
            ]);

            $this->validatePromotionProductUnits($validated);

            $branchIds = collect($validated['branch_ids'] ?? [])->unique()->values()->all();
            $warehouseIds = collect($validated['warehouse_ids'] ?? [])->unique()->values()->all();
            if (empty($warehouseIds) && ! empty($validated['warehouse_id'])) {
                $warehouseIds = [(int) $validated['warehouse_id']];
            }
            $branchScopeType = $validated['branch_scope_type'] ?? (empty($branchIds) ? 'ALL' : 'SELECTED');
            $warehouseScopeType = $validated['warehouse_scope_type'] ?? (empty($warehouseIds) ? 'ALL' : 'SELECTED');
            $promoMode = $validated['promo_mode'] ?? ($validated['promo_type'] === 'PRICE_OVERRIDE' ? 'MIX_MATCH' : 'NORMAL');
            $conditionType = $validated['condition_type']
                ?? $validated['tiers'][0]['condition']['condition_type']
                ?? $validated['tiers'][0]['conditions'][0]['condition_type']
                ?? 'NONE';

            if ($validated['promo_type'] === 'FOC') {
                if ($branchScopeType !== 'SELECTED') {
                    throw ValidationException::withMessages([
                        'branch_scope_type' => 'FOC promotions require branch_scope_type SELECTED.',
                    ]);
                }

                if (
                    ! empty($validated['warehouse_id'])
                    || ! empty($warehouseIds)
                    || ($validated['warehouse_scope_type'] ?? 'ALL') === 'SELECTED'
                ) {
                    throw ValidationException::withMessages([
                        'warehouse_ids' => 'FOC allocation warehouses are derived from the selected branches.',
                    ]);
                }

                $warehouseScopeType = 'ALL';
                $warehouseIds = [];
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

            if (
                $validated['promo_type'] === 'PRICE_OVERRIDE'
                && ! isset($validated['override_price'])
                && ! $this->hasTierOverridePrice($validated)
            ) {
                throw ValidationException::withMessages([
                    'override_price' => 'override_price or tier reward override_price is required for price override promotions.',
                ]);
            }

            $tiers = $this->normalizePromotionTiers($validated);
            $productIds = $this->promotionProductIds($validated);

            Log::info('Creating promotion with data', $validated);

            $promotion = DB::transaction(function () use (
                $validated,
                $branchIds,
                $warehouseIds,
                $branchScopeType,
                $warehouseScopeType,
                $promoMode,
                $conditionType,
                $tiers,
                $productIds
            ) {
                if ($validated['promo_type'] === 'PRODUCT_DISCOUNT') {
                    $this->validateProductDiscountEligibility(
                        $productIds,
                        $validated['start_at'],
                        $validated['end_at']
                    );
                }

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

                if (array_key_exists('products', $validated) || array_key_exists('promotion_products', $validated)) {
                    $this->syncPromotionProducts($promotion, $validated);
                }

                $targetBranchIds = $this->syncPromotionBranches($promotion, $branchScopeType, $branchIds);

                if ($promotion->promo_type === 'FOC') {
                    $this->validateFocBranchWarehouses($targetBranchIds);
                }

                $this->syncPromotionWarehouses(
                    $promotion,
                    $warehouseScopeType,
                    $warehouseIds,
                    $targetBranchIds
                );

                foreach ($tiers as $index => $tierData) {
                    $tier = $index + 1;
                    $conditions = $tierData['conditions'] ?? [];

                    foreach ($conditions as $conditionIndex => $condition) {
                        $promotion->conditions()->create([
                            'product_id' => $condition['product_id'] ?? null,
                            ...$this->promotionUomPayload($condition),
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
                            ...$this->promotionUomPayload($reward),
                            'reward_qty' => $reward['reward_qty'] ?? null,
                            'reward_value' => $reward['reward_value'] ?? null,
                            'override_price' => $reward['override_price'] ?? null,
                            'tier' => $tier,
                        ]);
                    }
                }

                if ($promotion->promo_type === 'FOC') {
                    $this->syncPromotionFocAllocations(
                        $promotion,
                        $validated['foc_allocations'] ?? [],
                        $targetBranchIds
                    );

                    if ((int) $promotion->status_id === $this->statusIdByName('inactive')) {
                        $this->closePromotionFocAllocations($promotion);
                    }
                }

                return $promotion;
            });

            return (new PromotionResource(
                $promotion->load([
                    'products',
                    'conditions.product',
                    'conditions.productUnit.unit',
                    'conditions.unit',
                    'rewards.product',
                    'rewards.productUnit.unit',
                    'rewards.unit',
                    'focAllocations.product',
                    'focAllocations.productUnit.unit',
                    'focAllocations.unit',
                    'focAllocations.branch',
                    'focAllocations.warehouse',
                    'branches',
                    'warehouses',
                ])
            ))->response()->setStatusCode(201);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Failed to create promotion',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
            ], 500);
        }
    }

    public function show(string $id)
    {
        $promotion = Promotion::with([
            'products',
            'conditions.product',
            'conditions.productUnit.unit',
            'conditions.unit',
            'rewards.product',
            'rewards.productUnit.unit',
            'rewards.unit',
            'focAllocations.product',
            'focAllocations.productUnit.unit',
            'focAllocations.unit',
            'focAllocations.branch',
            'focAllocations.warehouse',
            'branches',
            'warehouses',
        ])->findOrFail($id);

        return new PromotionResource($promotion);
    }

    public function update(Request $request, string $id)
    {
        $promotion = Promotion::findOrFail($id);
        $this->normalizePromotionProductsInput($request);

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
            'promotion_products' => 'nullable|array',
            'promotion_products.*.product_id' => 'required_with:promotion_products|exists:products,id',
            'promotion_products.*.product_unit_id' => 'nullable|exists:product_units,id',
            'promotion_products.*.unit_id' => 'nullable|exists:units,id',
            'promotion_products.*.max_qty_per_sales_order' => 'nullable|integer|min:1',
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
            'tiers.*.condition.product_unit_id' => 'nullable|exists:product_units,id',
            'tiers.*.condition.unit_id' => 'nullable|exists:units,id',
            'tiers.*.condition.unit_name' => 'nullable|string|max:255',
            'tiers.*.condition.conversion_to_base' => 'nullable|numeric|gt:0',
            'tiers.*.condition.operator' => 'nullable|string|max:10',
            'tiers.*.condition.target_value_to' => 'nullable|numeric|min:0',
            'tiers.*.conditions' => 'nullable|array',
            'tiers.*.conditions.*.id' => 'nullable|exists:promotion_conditions,id',
            'tiers.*.conditions.*.condition_type' => 'required_with:tiers.*.conditions|in:ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
            'tiers.*.conditions.*.target_value' => 'required_with:tiers.*.conditions|numeric|min:0',
            'tiers.*.conditions.*.product_id' => 'nullable|exists:products,id',
            'tiers.*.conditions.*.product_unit_id' => 'nullable|exists:product_units,id',
            'tiers.*.conditions.*.unit_id' => 'nullable|exists:units,id',
            'tiers.*.conditions.*.unit_name' => 'nullable|string|max:255',
            'tiers.*.conditions.*.conversion_to_base' => 'nullable|numeric|gt:0',
            'tiers.*.conditions.*.operator' => 'nullable|string|max:10',
            'tiers.*.conditions.*.target_value_to' => 'nullable|numeric|min:0',
            'tiers.*.reward' => 'nullable|array',
            'tiers.*.reward.id' => 'nullable|exists:promotion_rewards,id',
            'tiers.*.reward.reward_value' => 'nullable|numeric|min:0',
            'tiers.*.reward.reward_qty' => 'nullable|integer|min:1',
            'tiers.*.reward.product_id' => 'nullable|exists:products,id',
            'tiers.*.reward.product_unit_id' => 'nullable|exists:product_units,id',
            'tiers.*.reward.unit_id' => 'nullable|exists:units,id',
            'tiers.*.reward.unit_name' => 'nullable|string|max:255',
            'tiers.*.reward.conversion_to_base' => 'nullable|numeric|gt:0',
            'tiers.*.reward.override_price' => 'nullable|numeric|min:0',
            'tiers.*.rewards' => 'nullable|array',
            'tiers.*.rewards.*.id' => 'nullable|exists:promotion_rewards,id',
            'tiers.*.rewards.*.product_id' => 'nullable|exists:products,id',
            'tiers.*.rewards.*.product_unit_id' => 'nullable|exists:product_units,id',
            'tiers.*.rewards.*.unit_id' => 'nullable|exists:units,id',
            'tiers.*.rewards.*.unit_name' => 'nullable|string|max:255',
            'tiers.*.rewards.*.conversion_to_base' => 'nullable|numeric|gt:0',
            'tiers.*.rewards.*.reward_qty' => 'nullable|integer|min:1',
            'tiers.*.rewards.*.reward_value' => 'nullable|numeric|min:0',
            'tiers.*.rewards.*.override_price' => 'nullable|numeric|min:0',
            'foc_allocations' => 'nullable|array',
            'foc_allocations.*.id' => 'nullable|exists:promotion_foc_allocations,id',
            'foc_allocations.*.branch_id' => 'required_with:foc_allocations|integer|exists:branches,id',
            'foc_allocations.*.product_id' => 'required_with:foc_allocations|exists:products,id',
            'foc_allocations.*.product_unit_id' => 'required_with:foc_allocations|exists:product_units,id',
            'foc_allocations.*.unit_id' => 'nullable|exists:units,id',
            'foc_allocations.*.unit_name' => 'nullable|string|max:255',
            'foc_allocations.*.unit_quantity' => 'nullable|numeric|min:0',
            'foc_allocations.*.base_quantity' => 'nullable|numeric|min:0',
            'foc_allocations.*.conversion_to_base' => 'nullable|numeric|gt:0',
            'foc_allocations.*.allocated_warehouse_id' => 'prohibited',
            'foc_allocations.*.allocated_qty' => 'required_with:foc_allocations|integer|min:1',
        ]);

        $this->validatePromotionProductUnits($validated);

        Log::info("Updating promotion ID {$id} with data", $validated);

        DB::beginTransaction();

        try {
            $promotion = Promotion::lockForUpdate()->findOrFail($id);
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

            if (empty($warehouseIds) && ! empty($validated['warehouse_id'])) {
                $warehouseIds = [(int) $validated['warehouse_id']];
            }

            if ($targetPromoType === 'FOC') {
                if ($branchScopeType !== 'SELECTED') {
                    throw ValidationException::withMessages([
                        'branch_scope_type' => 'FOC promotions require branch_scope_type SELECTED.',
                    ]);
                }

                if (
                    ! empty($validated['warehouse_id'])
                    || ! empty($validated['warehouse_ids'] ?? [])
                    || ($validated['warehouse_scope_type'] ?? 'ALL') === 'SELECTED'
                ) {
                    throw ValidationException::withMessages([
                        'warehouse_ids' => 'FOC allocation warehouses are derived from the selected branches.',
                    ]);
                }

                $warehouseScopeType = 'ALL';
                $warehouseIds = [];
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

            if (
                $targetPromoType === 'PRICE_OVERRIDE'
                && ! isset($validated['override_price'])
                && is_null($promotion->override_price)
                && ! $this->hasTierOverridePrice($validated)
            ) {
                throw ValidationException::withMessages([
                    'override_price' => 'override_price or tier reward override_price is required for price override promotions.',
                ]);
            }

            $lockedFocAllocations = $promotion->focAllocations()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $totalUsedBaseQty = (float) $lockedFocAllocations->sum(
                fn ($allocation) => $this->allocationUsedBaseQty($allocation)
            );

            $changesFocStructure = array_key_exists('branch_ids', $validated)
                || array_key_exists('tiers', $validated)
                || (($promotion->promo_type !== 'FOC') && $targetPromoType === 'FOC')
                || (($promotion->promo_type === 'FOC') && $targetPromoType !== 'FOC');

            if ($totalUsedBaseQty > 0 && $changesFocStructure) {
                throw ValidationException::withMessages([
                    'foc_allocations' => 'Cannot modify FOC rewards, branch scope, or promotion type after usage has started.',
                ]);
            }

            if (
                $targetPromoType === 'FOC'
                && $changesFocStructure
                && ! array_key_exists('foc_allocations', $validated)
            ) {
                throw ValidationException::withMessages([
                    'foc_allocations' => 'foc_allocations is required when changing FOC rewards or selected branches.',
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
            $targetStatusId = $this->promotionLifecycleStatusId($targetStartAt, $targetEndAt);

            if ($targetPromoType === 'PRODUCT_DISCOUNT') {
                $hasProductInput = array_key_exists('products', $validated)
                    || array_key_exists('promotion_products', $validated);
                $productIds = $hasProductInput
                    ? $this->promotionProductIds($validated)
                    : $promotion->products()
                        ->pluck('products.id')
                        ->map(fn ($productId) => (int) $productId)
                        ->unique()
                        ->values()
                        ->all();

                $this->validateProductDiscountEligibility(
                    $productIds,
                    $targetStartAt,
                    $targetEndAt,
                    (int) $promotion->id
                );
            }

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
                'status_id' => $targetStatusId,
                'start_at' => $targetStartAt,
                'end_at' => $targetEndAt,
                'updated_by' => $validated['updated_by'] ?? $promotion->updated_by,
                'is_synced' => false,
                'synced_at' => null,
            ]);

            if (array_key_exists('products', $validated) || array_key_exists('promotion_products', $validated)) {
                $this->syncPromotionProducts($promotion, $validated);
            }

            $targetBranchIds = $this->syncPromotionBranches($promotion, $branchScopeType, $branchIds);

            if ($promotion->promo_type === 'FOC') {
                $this->validateFocBranchWarehouses($targetBranchIds);
            }

            $this->syncPromotionWarehouses(
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

            if ($promotion->promo_type === 'FOC' && array_key_exists('foc_allocations', $validated)) {
                $this->syncPromotionFocAllocations(
                    $promotion,
                    $validated['foc_allocations'] ?? [],
                    $targetBranchIds
                );
            }

            if (
                $promotion->promo_type === 'FOC'
                && (int) $targetStatusId === (int) $inactiveStatusId
            ) {
                $this->closePromotionFocAllocations($promotion);
            }

            DB::commit();

            return (new PromotionResource(
                $promotion->load([
                    'products',
                    'conditions.product',
                    'conditions.productUnit.unit',
                    'conditions.unit',
                    'rewards.product',
                    'rewards.productUnit.unit',
                    'rewards.unit',
                    'focAllocations.product',
                    'focAllocations.productUnit.unit',
                    'focAllocations.unit',
                    'focAllocations.branch',
                    'focAllocations.warehouse',
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
                    : 'Internal Server Error',
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

            if (! is_null($promotion->void_at)) {
                DB::commit();

                return response()->json([
                    'message' => 'Promotion is already voided.',
                ], 200);
            }

            if ($promotion->promo_type === 'FOC') {
                $this->closePromotionFocAllocations($promotion);
            }

            $voidStatus = Status::where('name', 'void')->lockForUpdate()->firstOrFail();

            $promotion->update([
                'status_id' => $voidStatus->id,
                'void_at' => now(),
                'void_by' => $request->void_by,
                'updated_by' => $request->void_by ?? $promotion->updated_by,
                'is_synced' => false,
                'synced_at' => null,
            ]);

            $promotion->products()->sync([]);
            $promotion->branches()->sync([]);
            $promotion->warehouses()->sync([]);

            DB::commit();

            return response()->json([
                'message' => 'Promotion voided successfully.',
            ], 200);

        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'message' => 'Failed to void promotion',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error',
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

        Log::info('Checking price with request data', $request->all());

        $cartItems = collect($request->cart ?? [])
            ->values()
            ->map(function ($item, $index) {
                $item['product_id'] = (int) $item['product_id'];
                $item['product_unit_id'] = ! empty($item['product_unit_id']) ? (int) $item['product_unit_id'] : null;
                $item['unit_id'] = ! empty($item['unit_id']) ? (int) $item['unit_id'] : null;
                $item['qty'] = (float) ($item['qty'] ?? 0);
                $item['base_qty'] = (float) ($item['base_qty'] ?? $item['qty']);
                $item['_promo_line_key'] = $this->cartItemKey($item, $index);

                return $item;
            });

        if ($cartItems->isEmpty()) {
            return response()->json([
                'priced_items' => [],
                'items' => [],
                'order' => [
                    'total_discount' => 0,
                    'applied_promotions' => [],
                ],
                'foc_items' => [],
                'promotion_warnings' => [],
            ]);
        }

        $warehouseId = $request->warehouse_id ? (int) $request->warehouse_id : null;
        $branchId = $request->branch_id ? (int) $request->branch_id : null;

        if (! $branchId && $warehouseId) {
            $branchId = Branch::where('warehouse_id', $warehouseId)->value('id');
        }

        if ($branchId && ! $warehouseId) {
            $warehouseId = Branch::where('id', $branchId)->value('warehouse_id');
        }

        if (! $branchId) {
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

        $priceResolver = app(SellingPriceService::class);
        $cartItems = $cartItems->map(function ($item) use ($priceResolver, $branchId) {
            $pricing = $priceResolver->resolve(
                (int) $item['product_id'],
                $branchId,
                $item['product_unit_id'] ?? null,
                (float) $item['qty'],
                $item['unit_id'] ?? null
            );

            $item['submitted_price'] = isset($item['price']) ? (float) $item['price'] : null;
            $item['original_price'] = (float) $pricing['price'];
            $item['price'] = (float) $pricing['price'];
            $item['price_source'] = $pricing['source'];
            $item['product_unit_id'] = $pricing['product_unit_id'] ?? $item['product_unit_id'];
            $item['unit_id'] = $pricing['unit_id'] ?? $item['unit_id'];
            $item['unit_name'] = $pricing['unit_name'] ?? ($item['unit_name'] ?? null);
            $item['conversion_to_base'] = $pricing['conversion_to_base'] ?? ($item['conversion_to_base'] ?? null);
            $item['branch_product_id'] = $pricing['branch_product_id'] ?? null;
            $item['branch_product_unit_price_id'] = $pricing['branch_product_unit_price_id'] ?? null;
            $item['product_unit_price_range_id'] = $pricing['product_unit_price_range_id'] ?? null;
            $item['branch_product_unit_price_range_id'] = $pricing['branch_product_unit_price_range_id'] ?? null;

            return $item;
        });

        $totalQty = $cartItems->sum('qty');
        $totalAmount = $cartItems->sum(fn ($i) => $i['qty'] * $i['price']);

        $this->refreshPromotionLifecycleStatuses();

        $promotions = Promotion::with([
            'products',
            'conditions',
            'conditions.productUnit.unit',
            'rewards',
            'rewards.productUnit.unit',
            'focAllocations',
            'focAllocations.productUnit.unit',
            'focAllocations.branch',
            'focAllocations.warehouse',
        ])
            ->where('status_id', $this->statusIdByName('applied'))
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->whereHas('branches', fn ($q) => $q->where('branches.id', $branchId))
            ->when($warehouseId, function ($q) use ($warehouseId) {
                $q->whereHas('warehouses', fn ($warehouseQuery) => $warehouseQuery->where('warehouses.id', $warehouseId));
            })
            ->get();

        [$cartItems, $promotionWarnings] = $this->splitCartItemsForPriceOverrideLimits(
            $cartItems,
            $promotions->where('promo_type', 'PRICE_OVERRIDE')
        );

        $productDiscounts = [];
        $orderDiscountAmount = 0;
        $orderPromotions = [];
        $freeItems = [];
        $effectiveUnitPrices = [];
        $priceOverrideLineKeys = [];

        foreach ($cartItems as $item) {
            $effectiveUnitPrices[$item['_promo_line_key']] = (float) ($item['original_price'] ?? $item['price']);
        }

        foreach ($promotions->where('promo_type', 'PRODUCT_DISCOUNT') as $promotion) {
            foreach ($cartItems as $item) {
                $productId = (int) $item['product_id'];
                $hasProduct = $this->promotionAppliesToCartItem($promotion, $item);

                if (! $hasProduct) {
                    continue;
                }

                $lineKey = $item['_promo_line_key'];
                $currentPrice = (float) ($effectiveUnitPrices[$lineKey] ?? $item['price']);
                $discount = $promotion->discount_type === 'PERCENT'
                    ? ($currentPrice * $promotion->discount_value) / 100
                    : $promotion->discount_value;
                $discount = min($discount, $currentPrice);
                $discountedPrice = max(0, $currentPrice - $discount);
                $effectiveUnitPrices[$lineKey] = $discountedPrice;

                $productDiscounts[] = [
                    'product_id' => $productId,
                    'product_unit_id' => $item['product_unit_id'] ?? null,
                    'unit_id' => $item['unit_id'] ?? null,
                    'line_key' => $lineKey,
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
                $overrideTerms = $this->getPriceOverrideTerms($promotion, $conditions, (int) $tier);

                [$isEligible] = $this->evaluatePromotionConditions(
                    $overrideItems,
                    $promotion,
                    $conditions,
                    $overrideAmount,
                    $overrideQty,
                    $overrideTerms['quantity_target_override']
                );

                $overrideUnitPrice = $overrideTerms['unit_price'];

                if (! $isEligible || is_null($overrideUnitPrice)) {
                    continue;
                }

                if ($overrideTerms['uses_frontend_field_order']) {
                    Log::info("PRICE_OVERRIDE promotion ID {$promotion->id} uses frontend quantity/price field order", [
                        'qualified_quantity' => $overrideTerms['quantity_target_override'],
                        'bundle_price' => $overrideTerms['bundle_price'],
                        'override_unit_price' => $overrideUnitPrice,
                    ]);
                }

                Log::info("Evaluating PRICE_OVERRIDE promotion ID {$promotion->id} for tier {$tier}", [
                    'override_unit_price' => $overrideUnitPrice,
                    'override_items' => $overrideItems,
                    'override_qty' => $overrideQty,
                    'override_amount' => $overrideAmount,
                ]);

                foreach ($overrideItems as $item) {
                    $productId = (int) $item['product_id'];
                    $lineKey = $item['_promo_line_key'];
                    $originalPrice = (float) ($item['original_price'] ?? $item['price']);
                    $currentPrice = isset($priceOverrideLineKeys[$lineKey])
                        ? (float) ($effectiveUnitPrices[$lineKey] ?? $originalPrice)
                        : $originalPrice;
                    $discountedPrice = min($currentPrice, $overrideUnitPrice);
                    $discount = max(0, $currentPrice - $discountedPrice);
                    $isAlreadyOverridePrice = abs($currentPrice - $overrideUnitPrice) < 0.01;

                    if ($discount <= 0 && ! $isAlreadyOverridePrice) {
                        continue;
                    }

                    $effectiveUnitPrices[$lineKey] = $discountedPrice;
                    $priceOverrideLineKeys[$lineKey] = true;

                    $productDiscounts[] = [
                        'product_id' => $productId,
                        'product_unit_id' => $item['product_unit_id'] ?? null,
                        'unit_id' => $item['unit_id'] ?? null,
                        'line_key' => $lineKey,
                        'promotion_id' => $promotion->id,
                        'promo_type' => 'PRICE_OVERRIDE',
                        'tier' => (int) $tier,
                        'applied_qty' => (float) $item['qty'],
                        'discount_amount' => $discount,
                        'discount_type' => 'AMOUNT',
                        'discount_value' => $discount,
                        'override_price' => (float) $overrideUnitPrice,
                        'discount_price' => $discountedPrice,
                    ];
                }

                if ($promotion->promo_mode === 'TIER') {
                    break;
                }
            }
        }

        if (! empty($priceOverrideLineKeys)) {
            $productDiscounts = array_values(array_filter(
                $productDiscounts,
                fn ($discount) => $discount['promo_type'] !== 'PRODUCT_DISCOUNT'
                    || ! isset($priceOverrideLineKeys[$discount['line_key']])
            ));
        }

        $discountedCartItems = $cartItems->map(function ($item) use ($effectiveUnitPrices) {
            $lineKey = $item['_promo_line_key'];
            $item['original_price'] = $item['price'];
            $item['price'] = (float) ($effectiveUnitPrices[$lineKey] ?? $item['price']);

            return $item;
        });

        $discountedProductTotal = $discountedCartItems->sum(fn ($i) => $i['qty'] * $i['price']);

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

                if (! $isEligible) {
                    continue;
                }

                $reward = $promotion->rewards
                    ->where('tier', $tier)
                    ->first();

                if (! $reward) {
                    continue;
                }

                $discount = $reward->reward_value;

                if ($promotion->promo_mode === 'MULTIPLIER') {
                    $discount *= $multiplier;
                }

                if (! is_null($promotion->max_reward_value)) {
                    $discount = min($discount, $promotion->max_reward_value);
                }

                $discount = min($discount, max(0, $discountedProductTotal - $orderDiscountAmount));
                $orderDiscountAmount += $discount;

                $orderPromotions[] = [
                    'promotion_id' => $promotion->id,
                    'tier' => $tier,
                    'discount' => $discount,
                ];

                if ($promotion->promo_mode === 'TIER') {
                    break;
                }
            }
        }

        $finalOrderAmount = max(0, $discountedProductTotal - $orderDiscountAmount);

        $focAvailabilityQuery = Inventory::query()
            ->select('product_id', DB::raw('SUM(foc_qty) as available_foc_qty'))
            ->groupBy('product_id');

        if (! empty($warehouseId)) {
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

                if (! $isEligible) {
                    continue;
                }

                $tierRewards = $promotion->rewards->where('tier', $tier);
                $totalFreeQtyForPromo = collect($freeItems)
                    ->where('promotion_id', $promotion->id)
                    ->sum('base_qty');

                foreach ($tierRewards as $reward) {
                    $productId = (int) $reward->product_id;
                    $unitQty = (float) $reward->reward_qty;

                    if ($promotion->promo_mode === 'MULTIPLIER') {
                        $unitQty *= $multiplier;
                    }

                    $baseQty = $this->rewardBaseQuantity($reward, $unitQty);

                    if (! is_null($promotion->max_reward_value)) {
                        $remainingQty = $promotion->max_reward_value - $totalFreeQtyForPromo;

                        if ($remainingQty <= 0) {
                            break;
                        }

                        $baseQty = min($baseQty, $remainingQty);
                        $unitQty = $this->rewardUnitQuantityFromBase($reward, $baseQty);
                    }

                    $matchingPools = $promotion->focAllocations
                        ->where('product_id', $productId)
                        ->filter(fn ($allocation) => $this->focAllocationMatchesRewardUnit(
                            $allocation->product_unit_id,
                            $reward->product_unit_id
                        ))
                        ->where('branch_id', (int) $branchId);

                    if (! empty($warehouseId)) {
                        $matchingPools = $matchingPools
                            ->where('allocated_warehouse_id', (int) $warehouseId);
                    }

                    if ($matchingPools->isEmpty()) {
                        continue;
                    }

                    $allocatedQty = (float) $matchingPools->sum(
                        fn ($allocation) => $this->allocationAllocatedBaseQty($allocation)
                    );
                    $usedQty = (float) $matchingPools->sum(
                        fn ($allocation) => $this->allocationUsedBaseQty($allocation)
                    );
                    $poolKey = $promotion->id.':'.$branchId.':'.$productId.':'.((int) ($reward->product_unit_id ?? 0)).':'.((int) ($warehouseId ?? 0));
                    $reservedPoolQty = (int) ($focReservedByPool[$poolKey] ?? 0);
                    $remainingAllocatableQty = max(0, $allocatedQty - $usedQty - $reservedPoolQty);

                    if ($remainingAllocatableQty <= 0) {
                        continue;
                    }

                    $baseQty = min($baseQty, $remainingAllocatableQty);
                    $availableQty = ($focAvailability[$productId] ?? 0)
                        - ($focReserved[$productId] ?? 0);

                    if ($availableQty <= 0) {
                        continue;
                    }

                    $baseQty = min($baseQty, $availableQty);
                    $unitQty = $this->rewardUnitQuantityFromBase($reward, $baseQty);

                    if ($baseQty <= 0) {
                        continue;
                    }

                    $freeItems[] = [
                        'product_id' => $productId,
                        'product_unit_id' => $reward->product_unit_id,
                        'unit_id' => $reward->unit_id,
                        'unit_name' => $reward->unit_name,
                        'conversion_to_base' => $reward->conversion_to_base,
                        'qty' => $unitQty,
                        'unit_quantity' => $unitQty,
                        'base_qty' => $baseQty,
                        'reward_id' => (int) $reward->id,
                        'promotion_id' => $promotion->id,
                    ];

                    $focReserved[$productId] = ($focReserved[$productId] ?? 0) + $baseQty;
                    $focReservedByPool[$poolKey] = ($focReservedByPool[$poolKey] ?? 0) + $baseQty;
                    $totalFreeQtyForPromo += $baseQty;
                }

                if ($promotion->promo_mode === 'TIER') {
                    break;
                }
            }
        }

        $discountsByLine = collect($productDiscounts)
            ->groupBy('line_key')
            ->map(fn ($discounts) => $discounts->last());
        $priceOverridesByLine = collect($productDiscounts)
            ->where('promo_type', 'PRICE_OVERRIDE')
            ->groupBy('line_key')
            ->map(fn ($discounts) => $discounts->last());

        $pricedItems = $cartItems->map(function ($item) use (
            $discountsByLine,
            $priceOverridesByLine,
            $effectiveUnitPrices
        ) {
            $lineKey = $item['_promo_line_key'];
            $discount = $discountsByLine[$lineKey] ?? null;
            $priceOverride = $priceOverridesByLine[$lineKey] ?? null;
            $originalPrice = (float) ($item['original_price'] ?? $item['price']);
            $finalPrice = (float) ($effectiveUnitPrices[$lineKey] ?? $originalPrice);
            $isExcessQuantity = ! empty($item['_price_override_excess_promotion_ids'] ?? []);

            return [
                'line_key' => $lineKey,
                'source_line_key' => $item['source_line_key'] ?? $lineKey,
                'product_id' => $item['product_id'],
                'product_unit_id' => $item['product_unit_id'] ?? null,
                'unit_id' => $item['unit_id'] ?? null,
                'unit_name' => $item['unit_name'] ?? null,
                'conversion_to_base' => $item['conversion_to_base'] ?? null,
                'qty' => $item['qty'],
                'base_qty' => $item['base_qty'] ?? $item['qty'],
                'price' => $originalPrice,
                'original_price' => $originalPrice,
                'final_price' => $finalPrice,
                'discount_price' => $discount ? $finalPrice : null,
                'promotion_id' => $priceOverride['promotion_id'] ?? ($discount['promotion_id'] ?? null),
                'promo_type' => $priceOverride['promo_type'] ?? ($discount['promo_type'] ?? null),
                'is_promotion_line' => ! is_null($priceOverride),
                'is_excess_quantity' => $isExcessQuantity,
                'split_type' => $priceOverride
                    ? 'PROMOTION'
                    : ($isExcessQuantity ? 'EXCESS' : 'STANDARD'),
                'price_source' => $item['price_source'] ?? null,
                'branch_product_id' => $item['branch_product_id'] ?? null,
                'branch_product_unit_price_id' => $item['branch_product_unit_price_id'] ?? null,
                'product_unit_price_range_id' => $item['product_unit_price_range_id'] ?? null,
                'branch_product_unit_price_range_id' => $item['branch_product_unit_price_range_id'] ?? null,
            ];
        })->values();

        Log::info('Price check result', [
            'priced_items' => $pricedItems,
            'items' => $productDiscounts,
            'order' => [
                'total_discount' => $orderDiscountAmount,
                'subtotal_after_product_discounts' => $discountedProductTotal,
                'final_amount' => $finalOrderAmount,
                'applied_promotions' => $orderPromotions,
            ],
            'foc_items' => $freeItems,
            'promotion_warnings' => $promotionWarnings,
        ]);

        return response()->json([
            'priced_items' => $pricedItems,
            'items' => $productDiscounts,
            'order' => [
                'total_discount' => $orderDiscountAmount,
                'subtotal_after_product_discounts' => $discountedProductTotal,
                'final_amount' => $finalOrderAmount,
                'applied_promotions' => $orderPromotions,
            ],
            'foc_items' => $freeItems,
            'promotion_warnings' => $promotionWarnings,
        ]);
    }

    private function getConditionCartItems($cartItems, Promotion $promotion, $condition)
    {
        if (! empty($condition->product_unit_id)) {
            return $cartItems->filter(fn ($item) => (int) $item['product_id'] === (int) $condition->product_id
                && $this->sameNullableId($item['product_unit_id'] ?? null, $condition->product_unit_id)
            );
        }

        if (! empty($condition->product_id)) {
            return $cartItems->where('product_id', $condition->product_id);
        }

        if ($promotion->products->isNotEmpty()) {
            return $cartItems->filter(fn ($item) => $this->promotionAppliesToCartItem($promotion, $item));
        }

        return $cartItems;
    }

    private function evaluatePromotionConditions(
        $cartItems,
        Promotion $promotion,
        $conditions,
        float $orderAmount,
        $orderQty,
        ?float $quantityTargetOverride = null
    ): array {
        $isEligible = true;
        $multipliers = [];

        foreach ($conditions as $condition) {
            $eligible = false;
            $multiplier = null;
            $isQuantityCondition = in_array($condition->condition_type, ['ITEM_QTY', 'ORDER_QTY'], true);
            $targetValue = $isQuantityCondition && ! is_null($quantityTargetOverride)
                ? $quantityTargetOverride
                : (float) ($condition->target_value ?? 0);

            switch ($condition->condition_type) {
                case 'ITEM_QTY':
                    $qty = $this->getConditionCartItems($cartItems, $promotion, $condition)
                        ->sum('qty');

                    $eligible = $this->comparePromotionValue($qty, $condition, $targetValue);
                    $multiplier = $targetValue > 0 ? floor($qty / $targetValue) : 1;
                    break;

                case 'ITEM_AMOUNT':
                    $amount = $this->getConditionCartItems($cartItems, $promotion, $condition)
                        ->sum(fn ($i) => $i['qty'] * $i['price']);

                    $eligible = $this->comparePromotionValue($amount, $condition, $targetValue);
                    $multiplier = $targetValue > 0 ? floor($amount / $targetValue) : 1;
                    break;

                case 'ORDER_AMOUNT':
                    $eligible = $this->comparePromotionValue($orderAmount, $condition, $targetValue);
                    $multiplier = $targetValue > 0 ? floor($orderAmount / $targetValue) : 1;
                    break;

                case 'ORDER_QTY':
                    $eligible = $this->comparePromotionValue($orderQty, $condition, $targetValue);
                    $multiplier = $targetValue > 0 ? floor($orderQty / $targetValue) : 1;
                    break;
            }

            if (! $eligible) {
                $isEligible = false;
                break;
            }

            if (! is_null($multiplier)) {
                $multipliers[] = max(1, (int) $multiplier);
            }
        }

        return [
            $isEligible,
            count($multipliers) ? min($multipliers) : 1,
        ];
    }

    private function comparePromotionValue($actualValue, $condition, ?float $targetValueOverride = null): bool
    {
        $actualValue = (float) $actualValue;
        $targetValue = $targetValueOverride ?? (float) ($condition->target_value ?? 0);
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

    private function cartItemKey(array $item, int $index): string
    {
        return implode(':', [
            $index,
            (int) ($item['product_id'] ?? 0),
            (int) ($item['product_unit_id'] ?? 0),
        ]);
    }

    private function promotionAppliesToCartItem(Promotion $promotion, array $item): bool
    {
        if ($promotion->products->isEmpty()) {
            return true;
        }

        return $promotion->products->contains(function ($product) use ($item) {
            if ((int) $product->id !== (int) $item['product_id']) {
                return false;
            }

            $pivotProductUnitId = $product->pivot->product_unit_id ?? null;

            if (empty($pivotProductUnitId)) {
                return true;
            }

            return $this->sameNullableId($item['product_unit_id'] ?? null, $pivotProductUnitId);
        });
    }

    private function sameNullableId($left, $right): bool
    {
        if (is_null($left) || $left === '') {
            $left = null;
        }

        if (is_null($right) || $right === '') {
            $right = null;
        }

        return is_null($left) && is_null($right)
            || (! is_null($left) && ! is_null($right) && (int) $left === (int) $right);
    }

    private function rewardBaseQuantity(PromotionReward $reward, float $unitQty): float
    {
        $conversion = $reward->conversion_to_base ? (float) $reward->conversion_to_base : 1;

        return $unitQty * $conversion;
    }

    private function rewardUnitQuantityFromBase(PromotionReward $reward, float $baseQty): float
    {
        $conversion = $reward->conversion_to_base ? (float) $reward->conversion_to_base : 1;

        if ($conversion <= 0) {
            return $baseQty;
        }

        return $baseQty / $conversion;
    }

    private function focAllocationMatchesRewardUnit($allocationProductUnitId, $rewardProductUnitId): bool
    {
        if (empty($allocationProductUnitId) || empty($rewardProductUnitId)) {
            return true;
        }

        return (int) $allocationProductUnitId === (int) $rewardProductUnitId;
    }

    private function getPriceOverrideCartItems($cartItems, Promotion $promotion, $conditions)
    {
        $cartItems = $cartItems->filter(function ($item) use ($promotion) {
            if (! array_key_exists('_price_override_eligible_promotion_ids', $item)) {
                return true;
            }

            return in_array(
                (int) $promotion->id,
                $item['_price_override_eligible_promotion_ids'],
                true
            );
        });

        if ($promotion->products->isNotEmpty()) {
            $returnCartItems = $cartItems->filter(fn ($item) => $this->promotionAppliesToCartItem($promotion, $item));

            Log::info('Price Override Cart Items after Promotion Product ID filter', [
                'cart_items' => $returnCartItems->values()->all(),
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
            'condition_product_ids' => $conditionProductIds,
        ]);

        if (! empty($conditionProductIds)) {
            return $cartItems->filter(function ($item) use ($conditions, $conditionProductIds) {
                $matchingConditions = $conditions->filter(fn ($condition) => in_array((int) $item['product_id'], $conditionProductIds, true)
                    && (
                        empty($condition->product_unit_id)
                        || $this->sameNullableId($item['product_unit_id'] ?? null, $condition->product_unit_id)
                    )
                );

                return $matchingConditions->isNotEmpty();
            });
        }

        return $cartItems;
    }

    private function splitCartItemsForPriceOverrideLimits($cartItems, $promotions): array
    {
        $eligibleQuantities = [];
        $matchedLines = [];
        $warnings = [];

        foreach ($promotions as $promotion) {
            $promotionId = (int) $promotion->id;

            foreach ($cartItems as $item) {
                $lineKey = $item['_promo_line_key'];
                $eligibleQuantities[$promotionId][$lineKey] = 0.0;
                $matchedLines[$promotionId][$lineKey] = false;
            }

            if ($promotion->products->isEmpty()) {
                foreach ($cartItems as $item) {
                    $lineKey = $item['_promo_line_key'];
                    $eligibleQuantities[$promotionId][$lineKey] = (float) $item['qty'];
                    $matchedLines[$promotionId][$lineKey] = true;
                }

                continue;
            }

            $groups = [];

            foreach ($cartItems as $item) {
                $product = $this->matchingPriceOverrideProductConfiguration($promotion, $item);

                if (! $product) {
                    continue;
                }

                $lineKey = $item['_promo_line_key'];
                $configurationKey = implode(':', [
                    (int) $product->id,
                    (int) ($product->pivot->product_unit_id ?? 0),
                    (int) ($product->pivot->unit_id ?? 0),
                ]);

                $groups[$configurationKey]['product'] = $product;
                $groups[$configurationKey]['items'][] = $item;
                $matchedLines[$promotionId][$lineKey] = true;
            }

            foreach ($groups as $group) {
                $product = $group['product'];
                $maxQty = $product->pivot->max_qty_per_sales_order;
                $remainingQty = is_null($maxQty) ? null : (float) $maxQty;
                $requestedQty = 0.0;
                $promotionQty = 0.0;

                foreach ($group['items'] as $item) {
                    $lineKey = $item['_promo_line_key'];
                    $lineQty = max(0, (float) $item['qty']);
                    $eligibleQty = is_null($remainingQty)
                        ? $lineQty
                        : min($lineQty, max(0, $remainingQty));

                    $requestedQty += $lineQty;
                    $promotionQty += $eligibleQty;
                    $eligibleQuantities[$promotionId][$lineKey] = $eligibleQty;

                    if (! is_null($remainingQty)) {
                        $remainingQty = max(0, $remainingQty - $eligibleQty);
                    }
                }

                if (is_null($maxQty) || $requestedQty <= (float) $maxQty + 0.000001) {
                    continue;
                }

                $productName = $product->name ?: "Product {$product->id}";
                $excessQty = max(0, $requestedQty - $promotionQty);

                $warnings[] = [
                    'code' => 'MAX_QTY_PER_SALES_ORDER_EXCEEDED',
                    'message' => "{$productName} has reached the promotion quantity limit for this sale. Any quantity exceeding the limit will be charged at the normal selling price.",
                    'promotion_id' => $promotionId,
                    'product_id' => (int) $product->id,
                    'product_name' => $productName,
                    'product_unit_id' => ! empty($product->pivot->product_unit_id)
                        ? (int) $product->pivot->product_unit_id
                        : null,
                    'requested_qty' => $requestedQty,
                    'promotion_qty' => $promotionQty,
                    'excess_qty' => $excessQty,
                    'max_qty_per_sales_order' => (float) $maxQty,
                ];

                Log::info("PRICE_OVERRIDE promotion ID {$promotionId} capped a product at its sales-order quantity limit", [
                    'product_id' => (int) $product->id,
                    'product_unit_id' => $product->pivot->product_unit_id,
                    'requested_qty' => $requestedQty,
                    'promotion_qty' => $promotionQty,
                    'excess_qty' => $excessQty,
                    'max_qty_per_sales_order' => (float) $maxQty,
                ]);
            }
        }

        $splitCartItems = $cartItems->flatMap(function ($item) use ($eligibleQuantities, $matchedLines) {
            $lineKey = $item['_promo_line_key'];
            $lineQty = max(0, (float) $item['qty']);
            $boundaries = [0.0, $lineQty];

            foreach ($eligibleQuantities as $lineQuantities) {
                $eligibleQty = (float) ($lineQuantities[$lineKey] ?? 0);

                if ($eligibleQty > 0.000001 && $eligibleQty < $lineQty - 0.000001) {
                    $boundaries[] = $eligibleQty;
                }
            }

            $boundaries = collect($boundaries)
                ->sort()
                ->unique(fn ($boundary) => number_format((float) $boundary, 6, '.', ''))
                ->values();

            $segments = [];

            for ($index = 0; $index < $boundaries->count() - 1; $index++) {
                $segmentStart = (float) $boundaries[$index];
                $segmentEnd = (float) $boundaries[$index + 1];
                $segmentQty = $segmentEnd - $segmentStart;

                if ($segmentQty <= 0.000001) {
                    continue;
                }

                $eligiblePromotionIds = [];
                $excessPromotionIds = [];

                foreach ($eligibleQuantities as $promotionId => $lineQuantities) {
                    if (! ($matchedLines[$promotionId][$lineKey] ?? false)) {
                        continue;
                    }

                    $eligibleQty = (float) ($lineQuantities[$lineKey] ?? 0);

                    if ($segmentEnd <= $eligibleQty + 0.000001) {
                        $eligiblePromotionIds[] = (int) $promotionId;
                    } elseif ($eligibleQty < $lineQty - 0.000001) {
                        $excessPromotionIds[] = (int) $promotionId;
                    }
                }

                $segment = $item;
                $segment['source_line_key'] = $lineKey;
                $segment['_promo_line_key'] = $boundaries->count() > 2
                    ? "{$lineKey}:split:{$index}"
                    : $lineKey;
                $segment['qty'] = $segmentQty;
                $segment['base_qty'] = $lineQty > 0
                    ? (float) ($item['base_qty'] ?? $lineQty) * ($segmentQty / $lineQty)
                    : 0.0;
                $segment['_price_override_eligible_promotion_ids'] = $eligiblePromotionIds;
                $segment['_price_override_excess_promotion_ids'] = $excessPromotionIds;
                $segments[] = $segment;
            }

            return $segments;
        })->values();

        return [$splitCartItems, collect($warnings)->unique(fn ($warning) => implode(':', [
            $warning['promotion_id'],
            $warning['product_id'],
            $warning['product_unit_id'] ?? 0,
        ]))->values()->all()];
    }

    private function matchingPriceOverrideProductConfiguration(Promotion $promotion, array $item)
    {
        $products = $promotion->products->filter(
            fn ($product) => (int) $product->id === (int) $item['product_id']
        );

        $exactUnitMatch = $products->first(fn ($product) =>
            ! empty($product->pivot->product_unit_id)
            && $this->sameNullableId(
                $item['product_unit_id'] ?? null,
                $product->pivot->product_unit_id
            )
        );

        if ($exactUnitMatch) {
            return $exactUnitMatch;
        }

        return $products->first(fn ($product) => empty($product->pivot->product_unit_id));
    }

    private function getPriceOverrideTerms(Promotion $promotion, $conditions, ?int $tier = null): array
    {
        if ($tier) {
            $rewardOverridePrice = $promotion->rewards
                ->where('tier', $tier)
                ->pluck('override_price')
                ->filter(fn ($price) => ! is_null($price))
                ->first();

            if (! is_null($rewardOverridePrice)) {
                return [
                    'unit_price' => (float) $rewardOverridePrice,
                    'bundle_price' => null,
                    'quantity_target_override' => null,
                    'uses_frontend_field_order' => false,
                ];
            }
        }

        $quantityTarget = $conditions
            ->whereIn('condition_type', ['ITEM_QTY', 'ORDER_QTY'])
            ->pluck('target_value')
            ->filter(fn ($targetValue) => (float) $targetValue > 0)
            ->map(fn ($targetValue) => (float) $targetValue)
            ->min();

        if (is_null($promotion->override_price)) {
            return [
                'unit_price' => null,
                'bundle_price' => null,
                'quantity_target_override' => null,
                'uses_frontend_field_order' => false,
            ];
        }

        $configuredOverridePrice = (float) $promotion->override_price;

        if (! $quantityTarget) {
            return [
                'unit_price' => $configuredOverridePrice,
                'bundle_price' => null,
                'quantity_target_override' => null,
                'uses_frontend_field_order' => false,
            ];
        }

        $usesFrontendFieldOrder = $promotion->promo_mode === 'MIX_MATCH'
            && $configuredOverridePrice > 0
            && floor($configuredOverridePrice) === $configuredOverridePrice
            && $configuredOverridePrice < $quantityTarget;

        if ($usesFrontendFieldOrder) {
            return [
                'unit_price' => round($quantityTarget / $configuredOverridePrice, 2),
                'bundle_price' => $quantityTarget,
                'quantity_target_override' => $configuredOverridePrice,
                'uses_frontend_field_order' => true,
            ];
        }

        return [
            'unit_price' => round($configuredOverridePrice / $quantityTarget, 2),
            'bundle_price' => $configuredOverridePrice,
            'quantity_target_override' => null,
            'uses_frontend_field_order' => false,
        ];
    }

    private function normalizePromotionTiers(array $validated): array
    {
        $tiers = [];

        foreach ($validated['tiers'] ?? [] as $tierData) {
            $conditions = [];

            if (! empty($tierData['conditions'])) {
                $conditions = $tierData['conditions'];
            } elseif (! empty($tierData['condition'])) {
                $conditions[] = $tierData['condition'];
            }

            $rewards = [];

            if (! empty($tierData['rewards'])) {
                $rewards = $tierData['rewards'];
            } elseif (! empty($tierData['reward'])) {
                $rewards[] = $tierData['reward'];
            }

            $tiers[] = [
                'conditions' => $conditions,
                'rewards' => $rewards,
            ];
        }

        if (empty($tiers) && ! empty($validated['condition_type']) && $validated['condition_type'] !== 'NONE') {
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
                    || ! array_key_exists('target_value', $condition)
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

    private function normalizePromotionProductsInput(Request $request): void
    {
        foreach (['products', 'promotion_products'] as $field) {
            if (! $request->has($field) || ! is_string($request->{$field})) {
                continue;
            }

            $decoded = json_decode($request->{$field}, true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $request->merge([$field => $decoded]);
            }
        }

        $products = $request->input('products');

        if (! is_array($products) || empty($products)) {
            return;
        }

        $hasObjectRows = collect($products)->contains(fn ($item) => is_array($item));

        if (! $hasObjectRows) {
            return;
        }

        $promotionProducts = collect($products)
            ->filter(fn ($item) => is_array($item) && ! empty($item['product_id']))
            ->map(fn ($item) => [
                'product_id' => (int) $item['product_id'],
                'product_unit_id' => $item['product_unit_id'] ?? null,
                'unit_id' => $item['unit_id'] ?? null,
                'max_qty_per_sales_order' => $item['max_qty_per_sales_order'] ?? null,
            ])
            ->values()
            ->all();

        $request->merge([
            'promotion_products' => $promotionProducts,
            'products' => collect($promotionProducts)
                ->pluck('product_id')
                ->unique()
                ->values()
                ->all(),
        ]);
    }

    private function promotionProductIds(array $validated): array
    {
        return collect($validated['promotion_products'] ?? [])
            ->pluck('product_id')
            ->merge($validated['products'] ?? [])
            ->filter(fn ($productId) => ! empty($productId))
            ->map(fn ($productId) => (int) $productId)
            ->unique()
            ->values()
            ->all();
    }

    private function validateProductDiscountEligibility(
        array $productIds,
        $startAt,
        $endAt,
        ?int $excludedPromotionId = null
    ): void {
        $productIds = collect($productIds)
            ->map(fn ($productId) => (int) $productId)
            ->filter(fn ($productId) => $productId > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return;
        }

        $conflicts = DB::table('promotions_products as promotion_product')
            ->join('promotions as promotion', 'promotion.id', '=', 'promotion_product.promotion_id')
            ->where('promotion.promo_type', 'PRODUCT_DISCOUNT')
            ->whereNull('promotion.void_at')
            ->where('promotion.start_at', '<=', $endAt)
            ->where('promotion.end_at', '>=', $startAt)
            ->whereIn('promotion_product.product_id', $productIds)
            ->when(
                $excludedPromotionId,
                fn ($query) => $query->where('promotion.id', '!=', $excludedPromotionId)
            )
            ->select([
                'promotion_product.product_id',
                'promotion.id as promotion_id',
                'promotion.name as promotion_name',
            ])
            ->orderBy('promotion_product.product_id')
            ->orderBy('promotion.id')
            ->lockForUpdate()
            ->get();

        if ($conflicts->isEmpty()) {
            return;
        }

        $conflictDetails = $conflicts
            ->unique(fn ($conflict) => "{$conflict->product_id}:{$conflict->promotion_id}")
            ->map(
                fn ($conflict) => "product {$conflict->product_id} "
                    ."({$conflict->promotion_name}, promotion {$conflict->promotion_id})"
            )
            ->implode(', ');

        throw ValidationException::withMessages([
            'products' => "The selected products already belong to overlapping PRODUCT_DISCOUNT promotions: {$conflictDetails}.",
        ]);
    }

    private function syncPromotionProducts(Promotion $promotion, array $validated): void
    {
        $rows = collect($validated['promotion_products'] ?? [])
            ->when(
                empty($validated['promotion_products']) && array_key_exists('products', $validated),
                fn ($collection) => collect($validated['products'] ?? [])->map(fn ($productId) => [
                    'product_id' => (int) $productId,
                    'product_unit_id' => null,
                    'unit_id' => null,
                    'max_qty_per_sales_order' => null,
                ])
            )
            ->map(function ($row) use ($promotion) {
                $uomPayload = $this->promotionUomPayload($row);

                return [
                    'promotion_id' => $promotion->id,
                    'product_id' => (int) $row['product_id'],
                    'product_unit_id' => $uomPayload['product_unit_id'],
                    'unit_id' => $uomPayload['unit_id'],
                    'max_qty_per_sales_order' => isset($row['max_qty_per_sales_order'])
                        ? (float) $row['max_qty_per_sales_order']
                        : null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            })
            ->unique(fn ($row) => implode(':', [
                $row['product_id'],
                $row['product_unit_id'] ?? 'null',
                $row['unit_id'] ?? 'null',
            ]))
            ->values()
            ->all();

        DB::table('promotions_products')
            ->where('promotion_id', $promotion->id)
            ->delete();

        if (! empty($rows)) {
            DB::table('promotions_products')->insert($rows);
        }
    }

    private function promotionUomPayload(array $data): array
    {
        $productUnitId = ! empty($data['product_unit_id']) ? (int) $data['product_unit_id'] : null;

        if ($productUnitId) {
            $productUnit = ProductUnit::with('unit')
                ->lockForUpdate()
                ->findOrFail($productUnitId);

            return [
                'product_unit_id' => $productUnit->id,
                'unit_id' => $productUnit->unit_id,
                'unit_name' => $productUnit->unit->name ?? ($data['unit_name'] ?? null),
                'conversion_to_base' => $productUnit->conversion_to_base,
            ];
        }

        return [
            'product_unit_id' => null,
            'unit_id' => $data['unit_id'] ?? null,
            'unit_name' => $data['unit_name'] ?? null,
            'conversion_to_base' => $data['conversion_to_base'] ?? null,
        ];
    }

    private function promotionFocAllocationPayload(array $allocation, int $index): array
    {
        $productId = (int) ($allocation['product_id'] ?? 0);
        $productUnitId = (int) ($allocation['product_unit_id'] ?? 0);
        $allocatedQty = (int) ($allocation['allocated_qty'] ?? 0);
        $productUnit = ProductUnit::with('unit')
            ->lockForUpdate()
            ->find($productUnitId);

        if (! $productUnit || (int) $productUnit->product_id !== $productId) {
            throw ValidationException::withMessages([
                "foc_allocations.{$index}.product_unit_id" => 'The selected product unit does not belong to the reward product.',
            ]);
        }

        $conversion = (float) $productUnit->conversion_to_base;
        $baseQuantity = $allocatedQty * $conversion;
        $roundedBaseQuantity = round($baseQuantity);

        if ($baseQuantity <= 0 || abs($baseQuantity - $roundedBaseQuantity) > 0.000001) {
            throw ValidationException::withMessages([
                "foc_allocations.{$index}.allocated_qty" => 'The allocated quantity must convert to a positive whole base-unit quantity.',
            ]);
        }

        return [
            'branch_id' => (int) $allocation['branch_id'],
            'product_id' => $productId,
            'product_unit_id' => $productUnit->id,
            'unit_id' => $productUnit->unit_id,
            'unit_name' => $productUnit->unit->name ?? null,
            'conversion_to_base' => $productUnit->conversion_to_base,
            'unit_quantity' => $allocatedQty,
            'base_quantity' => $roundedBaseQuantity,
            'allocated_qty' => $allocatedQty,
            'allocated_base_qty' => $roundedBaseQuantity,
        ];
    }

    private function validatePromotionProductUnits(array $validated): void
    {
        $items = collect($validated['promotion_products'] ?? []);

        foreach ($validated['tiers'] ?? [] as $tier) {
            if (! empty($tier['condition'])) {
                $items->push($tier['condition']);
            }

            foreach ($tier['conditions'] ?? [] as $condition) {
                $items->push($condition);
            }

            if (! empty($tier['reward'])) {
                $items->push($tier['reward']);
            }

            foreach ($tier['rewards'] ?? [] as $reward) {
                $items->push($reward);
            }
        }

        foreach ($validated['foc_allocations'] ?? [] as $allocation) {
            $items->push($allocation);
        }

        $productUnitIds = $items
            ->pluck('product_unit_id')
            ->filter()
            ->map(fn ($productUnitId) => (int) $productUnitId)
            ->unique()
            ->values();

        if ($productUnitIds->isEmpty()) {
            return;
        }

        $productUnits = ProductUnit::whereIn('id', $productUnitIds)
            ->pluck('product_id', 'id');

        foreach ($items as $item) {
            if (empty($item['product_unit_id']) || empty($item['product_id'])) {
                continue;
            }

            $productUnitId = (int) $item['product_unit_id'];
            $productId = (int) $item['product_id'];

            if ((int) ($productUnits[$productUnitId] ?? 0) !== $productId) {
                throw ValidationException::withMessages([
                    'product_unit_id' => "Product unit {$productUnitId} does not belong to product {$productId}.",
                ]);
            }
        }
    }

    private function hasTierOverridePrice(array $validated): bool
    {
        foreach ($validated['tiers'] ?? [] as $tier) {
            if (isset($tier['reward']['override_price'])) {
                return true;
            }

            foreach ($tier['rewards'] ?? [] as $reward) {
                if (isset($reward['override_price'])) {
                    return true;
                }
            }
        }

        return false;
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

    private function validateFocBranchWarehouses(array $branchIds): void
    {
        $branchIds = collect($branchIds)
            ->map(fn ($branchId) => (int) $branchId)
            ->unique()
            ->sort()
            ->values();
        $branches = Branch::whereIn('id', $branchIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'name', 'warehouse_id'])
            ->keyBy('id');
        $errors = [];

        foreach ($branchIds as $position => $branchId) {
            $branch = $branches->get($branchId);

            if (! $branch) {
                $errors["branch_ids.{$position}"] = 'The selected branch no longer exists.';
            } elseif (empty($branch->warehouse_id)) {
                $errors["branch_ids.{$position}"] = "{$branch->name} does not have an assigned warehouse.";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }
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

        if (! empty($invalidWarehouseIds)) {
            throw ValidationException::withMessages([
                'warehouse_ids' => 'Selected warehouses must be related to the selected promotion branches. Invalid warehouse IDs: '.implode(', ', $invalidWarehouseIds),
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

        if (! $statusId) {
            throw new \RuntimeException("Status '{$name}' is required.");
        }

        return (int) $statusId;
    }

    public function refreshPromotionLifecycleStatuses(): array
    {
        $now = now();
        $activeStatusId = $this->statusIdByName('active');
        $appliedStatusId = $this->statusIdByName('applied');
        $inactiveStatusId = $this->statusIdByName('inactive');

        return DB::transaction(function () use ($now, $activeStatusId, $appliedStatusId, $inactiveStatusId) {
            $scheduledPromotions = Promotion::whereNull('void_at')
                ->where('start_at', '>', $now)
                ->where('status_id', '!=', $activeStatusId)
                ->update(['status_id' => $activeStatusId]);

            $appliedPromotions = Promotion::whereNull('void_at')
                ->where('start_at', '<=', $now)
                ->where('end_at', '>=', $now)
                ->where('status_id', '!=', $appliedStatusId)
                ->update(['status_id' => $appliedStatusId]);

            $expiredPromotions = Promotion::with('focAllocations')
                ->whereNull('void_at')
                ->where('end_at', '<', $now)
                ->where(function ($query) use ($inactiveStatusId) {
                    $query->where('status_id', '!=', $inactiveStatusId)
                        ->orWhereHas('focAllocations', function ($allocationQuery) {
                            $allocationQuery->whereRaw(
                                'COALESCE(allocated_base_qty, allocated_qty, 0) > COALESCE(used_base_qty, used_qty, 0)'
                            );
                        });
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($expiredPromotions as $promotion) {
                if ($promotion->promo_type === 'FOC') {
                    $this->closePromotionFocAllocations($promotion);
                }

                $promotion->update(['status_id' => $inactiveStatusId]);
            }

            Log::info('Promotion lifecycle statuses refreshed', [
                'scheduled_promotions' => $scheduledPromotions,
                'applied_promotions' => $appliedPromotions,
                'expired_promotion_ids' => $expiredPromotions->pluck('id')->all(),
            ]);

            return [
                'scheduled_promotions' => $scheduledPromotions,
                'applied_promotions' => $appliedPromotions,
                'expired_promotions' => $expiredPromotions->count(),
                'checked_at' => $now->toDateTimeString(),
            ];
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
                    ...$this->promotionUomPayload($condition),
                    'group_no' => $condition['group_no'] ?? ($conditionIndex + 1),
                    'condition_type' => $condition['condition_type'],
                    'operator' => $condition['operator'] ?? '>=',
                    'target_value' => $condition['target_value'],
                    'target_value_to' => $condition['target_value_to'] ?? null,
                    'tier' => $tier,
                ];

                if (! empty($condition['id'])) {
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
                    ...$this->promotionUomPayload($reward),
                    'reward_qty' => $reward['reward_qty'] ?? null,
                    'reward_value' => $reward['reward_value'] ?? null,
                    'override_price' => $reward['override_price'] ?? null,
                    'tier' => $tier,
                ];

                if (! empty($reward['id'])) {
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
            $remaining = $this->allocationRemainingBaseQty($allocation);

            if ($remaining > 0 && ! empty($allocation->allocated_warehouse_id)) {
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
            $usedBaseQty = $this->allocationUsedBaseQty($allocation);
            $remaining = $this->allocationRemainingBaseQty($allocation);

            if ($remaining > 0 && ! empty($allocation->allocated_warehouse_id)) {
                $this->releaseFocStock(
                    (int) $allocation->product_id,
                    (int) $allocation->allocated_warehouse_id,
                    $remaining
                );
            }

            $allocation->update([
                'allocated_qty' => $usedQty,
                'allocated_base_qty' => $usedBaseQty,
                'unit_quantity' => $usedQty,
                'base_quantity' => $usedBaseQty,
            ]);
        }
    }

    private function allocationAllocatedBaseQty(PromotionFocAllocation $allocation): float
    {
        return (float) ($allocation->allocated_base_qty ?? $allocation->allocated_qty ?? 0);
    }

    private function allocationUsedBaseQty(PromotionFocAllocation $allocation): float
    {
        return (float) ($allocation->used_base_qty ?? $allocation->used_qty ?? 0);
    }

    private function allocationRemainingBaseQty(PromotionFocAllocation $allocation): int
    {
        return (int) max(
            0,
            round($this->allocationAllocatedBaseQty($allocation) - $this->allocationUsedBaseQty($allocation))
        );
    }

    private function syncPromotionFocAllocations(
        Promotion $promotion,
        array $focAllocations,
        array $branchIds
    ): void {
        $branchIds = collect($branchIds)
            ->map(fn ($branchId) => (int) $branchId)
            ->unique()
            ->sort()
            ->values();

        $branches = Branch::with('warehouse')
            ->whereIn('id', $branchIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($branches->count() !== $branchIds->count()) {
            throw ValidationException::withMessages([
                'branch_ids' => 'One or more selected promotion branches no longer exist.',
            ]);
        }

        $branchErrors = [];

        foreach ($branchIds as $position => $branchId) {
            if (empty($branches[$branchId]->warehouse_id)) {
                $branchErrors["branch_ids.{$position}"] = "{$branches[$branchId]->name} does not have an assigned warehouse.";
            }
        }

        if (! empty($branchErrors)) {
            throw ValidationException::withMessages($branchErrors);
        }

        $rewards = $promotion->rewards()
            ->where('reward_type', 'FREE_PRODUCT')
            ->whereNotNull('product_id')
            ->orderBy('id')
            ->get(['id', 'product_id', 'product_unit_id']);

        if ($rewards->isEmpty()) {
            throw ValidationException::withMessages([
                'tiers' => 'At least one FOC reward product is required.',
            ]);
        }

        $rewardKeys = [];

        foreach ($rewards as $reward) {
            if (empty($reward->product_unit_id)) {
                throw ValidationException::withMessages([
                    'tiers' => "FOC reward {$reward->id} must have a product unit.",
                ]);
            }

            $rewardKeys[$this->focRewardKey($reward->product_id, $reward->product_unit_id)] = $reward;
        }

        $existingAllocations = $promotion->focAllocations()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $existingById = $existingAllocations->keyBy('id');
        $existingByKey = $existingAllocations
            ->filter(fn ($allocation) => ! empty($allocation->branch_id))
            ->keyBy(fn ($allocation) => $this->focAllocationKey(
                $allocation->branch_id,
                $allocation->product_id,
                $allocation->product_unit_id
            ));

        $preparedAllocations = [];
        $seenKeys = [];
        $matchedExistingIds = [];
        $errors = [];

        foreach ($focAllocations as $index => $allocation) {
            $branchId = (int) ($allocation['branch_id'] ?? 0);

            if (! $branchIds->contains($branchId)) {
                $errors["foc_allocations.{$index}.branch_id"] = 'The allocation branch must be included in branch_ids.';

                continue;
            }

            $payload = $this->promotionFocAllocationPayload($allocation, $index);
            $branch = $branches[$branchId];
            $rewardKey = $this->focRewardKey($payload['product_id'], $payload['product_unit_id']);
            $allocationKey = $this->focAllocationKey(
                $branchId,
                $payload['product_id'],
                $payload['product_unit_id']
            );

            if (! isset($rewardKeys[$rewardKey])) {
                $errors["foc_allocations.{$index}.product_unit_id"] = 'The allocation product and unit are not an FOC reward for this promotion.';

                continue;
            }

            if (isset($seenKeys[$allocationKey])) {
                $errors["foc_allocations.{$index}.product_unit_id"] = 'Duplicate branch, product, and product-unit allocation.';

                continue;
            }

            $seenKeys[$allocationKey] = true;
            $keyAllocation = $existingByKey->get($allocationKey);
            $existingAllocation = $keyAllocation;

            if (! empty($allocation['id'])) {
                $existingAllocation = $existingById->get((int) $allocation['id']);

                if (! $existingAllocation) {
                    $errors["foc_allocations.{$index}.id"] = 'The allocation does not belong to this promotion.';

                    continue;
                }

                if ($keyAllocation && (int) $keyAllocation->id !== (int) $existingAllocation->id) {
                    $errors["foc_allocations.{$index}.id"] = 'Another existing allocation already owns this branch, product, and unit.';

                    continue;
                }

                if (
                    (int) $existingAllocation->product_id !== (int) $payload['product_id']
                    || (int) $existingAllocation->product_unit_id !== (int) $payload['product_unit_id']
                    || (
                        ! empty($existingAllocation->branch_id)
                        && (int) $existingAllocation->branch_id !== $branchId
                    )
                    || (
                        ! empty($existingAllocation->allocated_warehouse_id)
                        && (int) $existingAllocation->allocated_warehouse_id !== (int) $branch->warehouse_id
                    )
                ) {
                    $errors["foc_allocations.{$index}.id"] = 'An existing allocation cannot be moved to a different branch, product, unit, or warehouse.';

                    continue;
                }
            }

            if ($existingAllocation && isset($matchedExistingIds[$existingAllocation->id])) {
                $errors["foc_allocations.{$index}.id"] = 'The existing allocation was submitted more than once.';

                continue;
            }

            if ($existingAllocation) {
                $matchedExistingIds[$existingAllocation->id] = true;
                $usedBaseQty = $this->allocationUsedBaseQty($existingAllocation);

                if ((float) $payload['allocated_base_qty'] < $usedBaseQty) {
                    $errors["foc_allocations.{$index}.allocated_qty"] = "Allocated quantity cannot be lower than the used base quantity {$usedBaseQty}.";

                    continue;
                }
            }

            $payload['allocated_warehouse_id'] = (int) $branch->warehouse_id;
            $payload['_index'] = $index;
            $payload['_key'] = $allocationKey;
            $payload['_existing_id'] = $existingAllocation?->id;
            $preparedAllocations[$allocationKey] = $payload;
        }

        $missingMessages = [];

        foreach ($branchIds as $branchId) {
            foreach ($rewardKeys as $reward) {
                $expectedKey = $this->focAllocationKey(
                    $branchId,
                    $reward->product_id,
                    $reward->product_unit_id
                );

                if (! isset($preparedAllocations[$expectedKey])) {
                    $missingMessages[] = "{$branches[$branchId]->name} requires an allocation for reward product {$reward->product_id}, product unit {$reward->product_unit_id}.";
                }
            }
        }

        if (! empty($missingMessages)) {
            $errors['foc_allocations'] = $missingMessages;
        }

        $incomingExistingIds = collect($preparedAllocations)
            ->pluck('_existing_id')
            ->filter()
            ->map(fn ($allocationId) => (int) $allocationId)
            ->all();

        foreach ($existingAllocations->whereNotIn('id', $incomingExistingIds) as $existingAllocation) {
            if ($this->allocationUsedBaseQty($existingAllocation) > 0) {
                $errors['foc_allocations'][] = "Used allocation {$existingAllocation->id} cannot be removed.";
            }
        }

        if (! empty($errors)) {
            throw ValidationException::withMessages($errors);
        }

        $existingPoolTotals = [];

        foreach ($existingAllocations as $allocation) {
            if (empty($allocation->allocated_warehouse_id)) {
                continue;
            }

            $poolKey = $this->focStockPoolKey($allocation->allocated_warehouse_id, $allocation->product_id);
            $existingPoolTotals[$poolKey] = ($existingPoolTotals[$poolKey] ?? 0)
                + $this->allocationAllocatedBaseQty($allocation);
        }

        $targetPoolTotals = [];
        $targetPoolIndexes = [];

        foreach ($preparedAllocations as $payload) {
            $poolKey = $this->focStockPoolKey(
                $payload['allocated_warehouse_id'],
                $payload['product_id']
            );
            $targetPoolTotals[$poolKey] = ($targetPoolTotals[$poolKey] ?? 0)
                + (float) $payload['allocated_base_qty'];
            $targetPoolIndexes[$poolKey][] = $payload['_index'];
        }

        $poolKeys = collect(array_keys($existingPoolTotals + $targetPoolTotals))
            ->sort()
            ->values();
        $poolDeltas = [];
        $stockErrors = [];

        foreach ($poolKeys as $poolKey) {
            [$warehouseId, $productId] = array_map('intval', explode(':', $poolKey));
            $existingTotal = (float) ($existingPoolTotals[$poolKey] ?? 0);
            $targetTotal = (float) ($targetPoolTotals[$poolKey] ?? 0);
            $delta = (int) round($targetTotal - $existingTotal);
            $poolDeltas[$poolKey] = $delta;

            $inventories = Inventory::where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->whereNull('void_at')
                ->orderByRaw('expired_date IS NULL')
                ->orderBy('expired_date')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            if ($targetTotal > 0 && $inventories->isEmpty()) {
                $index = $targetPoolIndexes[$poolKey][0] ?? 0;
                $stockErrors["foc_allocations.{$index}.allocated_qty"] = "Product {$productId} does not exist in warehouse {$warehouseId} inventory.";

                continue;
            }

            if ($delta <= 0) {
                continue;
            }

            $availableBaseQty = (int) $inventories
                ->filter(fn ($inventory) => (int) $inventory->qty > 0)
                ->filter(fn ($inventory) => is_null($inventory->expired_date)
                    || \Carbon\Carbon::parse($inventory->expired_date)->startOfDay()->gte(today()))
                ->sum('qty');

            if ($availableBaseQty < $delta) {
                $index = $targetPoolIndexes[$poolKey][0] ?? 0;
                $capacity = $existingTotal + $availableBaseQty;
                $stockErrors["foc_allocations.{$index}.allocated_qty"] = "Combined branch allocations require {$targetTotal} base units in warehouse {$warehouseId}; the maximum allocation is {$capacity}.";
            }
        }

        if (! empty($stockErrors)) {
            throw ValidationException::withMessages($stockErrors);
        }

        foreach ($poolDeltas as $poolKey => $delta) {
            [$warehouseId, $productId] = array_map('intval', explode(':', $poolKey));

            if ($delta > 0) {
                $this->allocateFocStock($productId, $warehouseId, $delta);
            } elseif ($delta < 0) {
                $this->releaseFocStock($productId, $warehouseId, abs($delta));
            }
        }

        $savedAllocationIds = [];

        foreach ($preparedAllocations as $payload) {
            $existingAllocationId = $payload['_existing_id'];
            unset($payload['_index'], $payload['_key'], $payload['_existing_id']);

            if ($existingAllocationId) {
                $existingAllocation = $existingById[$existingAllocationId];
                $existingAllocation->update($payload);
                $savedAllocationIds[] = $existingAllocation->id;

                continue;
            }

            $newAllocation = PromotionFocAllocation::create([
                'promotion_id' => $promotion->id,
                ...$payload,
                'used_qty' => 0,
                'used_base_qty' => 0,
            ]);
            $savedAllocationIds[] = $newAllocation->id;
        }

        $promotion->focAllocations()
            ->whereNotIn('id', $savedAllocationIds)
            ->delete();
    }

    private function focRewardKey($productId, $productUnitId): string
    {
        return (int) $productId.':'.(int) $productUnitId;
    }

    private function focAllocationKey($branchId, $productId, $productUnitId): string
    {
        return (int) $branchId.':'.$this->focRewardKey($productId, $productUnitId);
    }

    private function focStockPoolKey($warehouseId, $productId): string
    {
        return (int) $warehouseId.':'.(int) $productId;
    }

    private function allocateFocStock(int $productId, int $warehouseId, int $qty): void
    {
        Log::info('Attempting to allocate FOC stock', [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'requested_qty' => $qty,
        ]);
        $inventories = Inventory::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->whereNull('void_at')
            ->where('qty', '>', 0)
            ->where(function ($query) {
                $query->whereNull('expired_date')
                    ->orWhereDate('expired_date', '>=', now()->toDateString());
            })
            ->lockForUpdate()
            ->orderByRaw('expired_date IS NULL')
            ->orderBy('expired_date')
            ->orderBy('id')
            ->get();

        $available = $inventories->sum('qty');

        if ($available < $qty) {
            throw ValidationException::withMessages([
                'foc_allocations' => "Insufficient stock for product {$productId} in warehouse {$warehouseId}.",
            ]);
        }

        $remaining = $qty;

        foreach ($inventories as $inv) {
            if ($remaining <= 0) {
                break;
            }

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
            ->whereNull('void_at')
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

        Log::info('Extracted FOC allocation map', ['allocation_map' => $allocationMap]);

        return $allocationMap;
    }
}
