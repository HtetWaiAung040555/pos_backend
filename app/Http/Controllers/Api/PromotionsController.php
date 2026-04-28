<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use App\Http\Resources\PromotionResource;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Status;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PromotionsController extends Controller
{

    public function index()
    {
        $now = now();

        $inactiveStatusId = Status::where('name', 'inactive')->value('id');
        $activeStatusId   = Status::where('name', 'active')->value('id');

        /* Activate promotions */
        Promotion::whereNull('void_at')
            ->where('start_at', '<=', $now)
            ->where('end_at', '>=', $now)
            ->where('status_id', '!=', $activeStatusId)
            ->update(['status_id' => $activeStatusId]);

        /* Deactivate promotions */
        Promotion::whereNull('void_at')
            ->where(function ($q) use ($now) {
                $q->where('start_at', '>', $now)
                ->orWhere('end_at', '<', $now);
            })
            ->where('status_id', '!=', $inactiveStatusId)
            ->update(['status_id' => $inactiveStatusId]);

        $promotions = Promotion::with(['products', 'conditions.product', 'rewards.product'])->latest()->get();

        return PromotionResource::collection($promotions);
    }

    public function store(Request $request)
    {
        try {

            $validated = $request->validate([
                'name' => 'required|string|max:255',
                'promo_type' => 'required|in:PRODUCT_DISCOUNT,ORDER_DISCOUNT,FOC',
                'promo_mode' => 'nullable|in:TIER,MULTIPLIER',
                'start_at' => 'required|date',
                'end_at' => 'required|date',
                'created_by' => 'required|exists:users,id',
                'products' => 'nullable|array',
                'products.*' => 'integer|exists:products,id',
                'discount_type' => 'nullable|in:PERCENT,AMOUNT',
                'discount_value' => 'nullable|numeric',
                'max_reward_value' => 'nullable|numeric|min:0',
                'tiers' => 'nullable|array',
                'tiers.*.condition.condition_type' => 'required_with:tiers|in:ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
                'tiers.*.condition.target_value' => 'required_with:tiers|numeric|min:0',
                'tiers.*.condition.product_id' => 'nullable|exists:products,id',
                'tiers.*.reward.reward_value' => 'nullable|numeric|min:0',
                'tiers.*.rewards' => 'nullable|array',
                'tiers.*.rewards.*.product_id' => 'required_with:tiers.*.rewards|exists:products,id',
                'tiers.*.rewards.*.reward_qty' => 'required_with:tiers.*.rewards|integer|min:1',
            ]);

            DB::beginTransaction();

            $promotion = Promotion::create([
                'name' => $validated['name'],
                'promo_type' => $validated['promo_type'],
                'promo_mode' => $validated['promo_mode'] ?? 'TIER',
                'discount_type' => $validated['discount_type'] ?? 'AMOUNT',
                'discount_value' => $validated['discount_value'] ?? 0,
                'max_reward_value' => $validated['max_reward_value'] ?? null,
                'start_at' => $validated['start_at'],
                'end_at' => $validated['end_at'],
                'status_id' => $request->input('status_id', 1),
                'created_by' => $validated['created_by'],
                'updated_by' => $validated['created_by'],
            ]);

            if ($promotion->promo_type === 'PRODUCT_DISCOUNT') {

                if (!empty($validated['products'])) {
                    $promotion->products()->sync($validated['products']);
                }

                DB::commit();
                return (new PromotionResource($promotion))
                ->response()
                ->setStatusCode(201);
            }

            if (!empty($validated['tiers'])) {

                foreach ($validated['tiers'] as $index => $tierData) {

                    $tier = $index + 1;

                    $promotion->conditions()->create([
                        'product_id' => $tierData['condition']['product_id'] ?? null,
                        'condition_type' => $tierData['condition']['condition_type'],
                        'target_value' => $tierData['condition']['target_value'],
                        'tier' => $tier
                    ]);

                    if ($promotion->promo_type === 'ORDER_DISCOUNT') {

                        $promotion->rewards()->create([
                            'reward_type' => 'DISCOUNT',
                            'reward_value' => $tierData['reward']['reward_value'] ?? 0,
                            'tier' => $tier
                        ]);
                    }

                    if ($promotion->promo_type === 'FOC') {

                        foreach ($tierData['rewards'] as $reward) {

                            $promotion->rewards()->create([
                                'reward_type' => 'FREE_PRODUCT',
                                'product_id' => $reward['product_id'],
                                'reward_qty' => $reward['reward_qty'],
                                'tier' => $tier
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            $promotion->load(['products', 'conditions.product', 'rewards.product']);

            return (new PromotionResource($promotion))
                ->response()
                ->setStatusCode(201);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'message' => 'Failed to create promotion',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], 500);
        }
    }

    public function show(string $id)
    {
        $promotion = Promotion::with(['products', 'conditions.product', 'rewards.product'])->findOrFail($id);

        return new PromotionResource($promotion);
    }

    public function update(Request $request, string $id)
    {
        $promotion = Promotion::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'promo_type' => 'sometimes|required|in:PRODUCT_DISCOUNT,ORDER_DISCOUNT,FOC',
            'promo_mode' => 'nullable|in:TIER,MULTIPLIER',
            'start_at' => 'sometimes|required|date',
            'end_at' => 'sometimes|required|date',
            'created_by' => 'sometimes|required|exists:users,id',
            'updated_by' => 'nullable|exists:users,id',
            'products' => 'nullable|array',
            'products.*' => 'integer|exists:products,id',
            'discount_type' => 'nullable|in:PERCENT,AMOUNT',
            'discount_value' => 'nullable|numeric',
            'max_reward_value' => 'nullable|numeric|min:0',
            'tiers' => 'nullable|array',
            'tiers.*.condition.condition_type' => 'required_with:tiers|in:ORDER_AMOUNT,ORDER_QTY,ITEM_QTY,ITEM_AMOUNT',
            'tiers.*.condition.target_value' => 'required_with:tiers|numeric|min:0',
            'tiers.*.condition.product_id' => 'nullable|exists:products,id',
            'tiers.*.reward.reward_value' => 'nullable|numeric|min:0',
            'tiers.*.rewards' => 'nullable|array',
            'tiers.*.rewards.*.product_id' => 'required_with:tiers.*.rewards|exists:products,id',
            'tiers.*.rewards.*.reward_qty' => 'required_with:tiers.*.rewards|integer|min:1',
        ]);

        Log::info("Updating promotion ID {$id} with data", $validated);

        DB::beginTransaction();
        try {
            $promotion->update([
                'name' => $validated['name'] ?? $promotion->name,
                'description' => $validated['description'] ?? $promotion->description,
                'promo_type' => $validated['promo_type'] ?? $promotion->promo_type,
                'promo_mode' => $validated['promo_mode'] ?? $promotion->promo_mode,
                'discount_type' => $validated['discount_type'] ?? $promotion->discount_type,
                'discount_value' => $validated['discount_value'] ?? $promotion->discount_value,
                'max_reward_value' => $validated['max_reward_value'] ?? $promotion->max_reward_value,
                'status_id' => $request->input('status_id', $promotion->status_id),
                'start_at' => $validated['start_at'] ?? $promotion->start_at,
                'end_at' => $validated['end_at'] ?? $promotion->end_at,
                'updated_by' => $validated['updated_by'] ?? $promotion->updated_by,
            ]);

            // PRODUCT_DISCOUNT
            if (($promotion->promo_type === 'PRODUCT_DISCOUNT') && array_key_exists('products', $validated)) {
                $promotion->products()->sync($validated['products'] ?? []);
            }

            // Remove old conditions and rewards if tiers are present
            if (!empty($validated['tiers'])) {
                $promotion->conditions()->delete();
                $promotion->rewards()->delete();

                foreach ($validated['tiers'] as $index => $tierData) {
                    $tier = $index + 1;

                    $promotion->conditions()->create([
                        'product_id' => $tierData['condition']['product_id'] ?? null,
                        'condition_type' => $tierData['condition']['condition_type'],
                        'target_value' => $tierData['condition']['target_value'],
                        'tier' => $tier
                    ]);

                    if ($promotion->promo_type === 'ORDER_DISCOUNT') {
                        $promotion->rewards()->create([
                            'reward_type' => 'DISCOUNT',
                            'reward_value' => $tierData['reward']['reward_value'] ?? 0,
                            'tier' => $tier
                        ]);
                    }

                    if ($promotion->promo_type === 'FOC' && !empty($tierData['rewards'])) {
                        foreach ($tierData['rewards'] as $reward) {
                            $promotion->rewards()->create([
                                'reward_type' => 'FREE_PRODUCT',
                                'product_id' => $reward['product_id'],
                                'reward_qty' => $reward['reward_qty'],
                                'tier' => $tier
                            ]);
                        }
                    }
                }
            }

            DB::commit();

            $promotion->load(['products', 'conditions.product', 'rewards.product']);
            return (new PromotionResource($promotion))->response()->setStatusCode(200);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Failed to update promotion',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal Server Error'
            ], 500);
        }
    }

    public function destroy(Request $request, string $id)
    {
        DB::beginTransaction();

        try {
            $promotion = Promotion::with('products')->findOrFail($id);

            $voidStatus = Status::where('name', 'void')->firstOrFail();

            $promotion->status_id = $voidStatus->id;
            $promotion->void_at   = now();
            $promotion->void_by   = $request->void_by;
            $promotion->save();

            $promotion->products()->sync([]);

            DB::commit();

            return response()->json([
                'message' => 'Promotion voided successfully.'
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();

            return response()->json([
                'error'   => 'Failed to void promotion',
                'details' => $e->getMessage()
            ], 500);
        }
    }

    // public function checkPrice(Request $request)
    // {
    //     $cartItems = collect($request->cart ?? []);

    //     if ($cartItems->isEmpty()) {
    //         return response()->json([
    //             'product_discounts' => [],
    //             'order_discount_amount' => 0,
    //             'free_items' => []
    //         ]);
    //     }

    //     $totalQty = $cartItems->sum('qty');
    //     $totalAmount = $cartItems->sum(fn($i) => $i['qty'] * $i['price']);

    //     $promotions = Promotion::with(['products', 'conditions', 'rewards.product'])
    //         ->where('status_id', 1)
    //         ->where('start_at', '<=', now())
    //         ->where('end_at', '>=', now())
    //         ->get();

    //     $productDiscounts = [];
    //     $orderDiscountAmount = 0;
    //     $freeItems = [];

    //     foreach ($promotions as $promotion) {

    //         if ($promotion->promo_type === 'PRODUCT_DISCOUNT') {

    //             foreach ($cartItems as $item) {

    //                 $hasProduct = $promotion->products
    //                     ->where('id', $item['product_id'])
    //                     ->isNotEmpty();

    //                 if (!$hasProduct) continue;

    //                 $discount = $promotion->discount_type === 'PERCENT'
    //                     ? ($item['price'] * $promotion->discount_value) / 100
    //                     : $promotion->discount_value;

    //                 $productDiscounts[] = [
    //                     'product_id' => $item['product_id'],
    //                     'promotion_id' => $promotion->id,
    //                     'discount_amount' => $discount,
    //                     'discount_type' => $promotion->discount_type,
    //                     'discount_value' => $promotion->discount_value
    //                 ];
    //             }

    //             continue;
    //         }


    //             $tiers = $promotion->conditions
    //                 ->groupBy('tier');

    //         if ($promotion->promo_mode === 'TIER') {
    //             $tiers = $tiers->sortKeysDesc();
    //         } else {
    //             $tiers = $tiers->sortKeys(); 
    //         }

    //         foreach ($tiers as $tier => $conditions) {

    //             $isEligible = true;
    //             $multipliers = [];

    //             foreach ($conditions as $condition) {

    //                 $eligible = false;
    //                 $multiplier = null;

    //                 switch ($condition->condition_type) {

    //                     case 'ITEM_QTY':
    //                         $qty = $cartItems
    //                             ->where('product_id', $condition->product_id)
    //                             ->sum('qty');

    //                         if ($qty >= $condition->target_value) {
    //                             $eligible = true;
    //                             $multiplier = floor($qty / $condition->target_value);
    //                         }
    //                         break;

    //                     case 'ITEM_AMOUNT':
    //                         $amount = $cartItems
    //                             ->where('product_id', $condition->product_id)
    //                             ->sum(fn($i) => $i['qty'] * $i['price']);

    //                         if ($amount >= $condition->target_value) {
    //                             $eligible = true;
    //                             $multiplier = floor($amount / $condition->target_value);
    //                         }
    //                         break;

    //                     case 'ORDER_AMOUNT':
    //                         if ($totalAmount >= $condition->target_value) {
    //                             $eligible = true;
    //                             $multiplier = floor($totalAmount / $condition->target_value);
    //                         }
    //                         break;

    //                     case 'ORDER_QTY':
    //                         if ($totalQty >= $condition->target_value) {
    //                             $eligible = true;
    //                             $multiplier = floor($totalQty / $condition->target_value);
    //                         }
    //                         break;
    //                 }

    //                 if (!$eligible) {
    //                     $isEligible = false;
    //                     break;
    //                 }

    //                 if (!is_null($multiplier)) {
    //                     $multipliers[] = $multiplier;
    //                 }
    //             }

    //             if (!$isEligible) continue;

    //             $multiplier = count($multipliers) ? min($multipliers) : 1;

    //             if ($promotion->promo_type === 'ORDER_DISCOUNT') {

    //                 $reward = $promotion->rewards
    //                     ->where('tier', $tier)
    //                     ->first();

    //                 if (!$reward) continue;

    //                 $discount = $reward->reward_value;

    //                 if ($promotion->promo_mode === 'MULTIPLIER') {
    //                     $discount *= $multiplier;
    //                 }

    //                 Log::info('Max Discount', [
    //                     'calculated_discount' => $discount,
    //                     'max_reward_value' => $promotion->max_reward_value
    //                 ]);

    //                 if (!is_null($promotion->max_reward_value)) {
    //                     $discount = min($discount, $promotion->max_reward_value);
    //                     Log::info("Applying max reward value cap for promotion ID {$promotion->id}", [
    //                         'calculated_discount' => $discount,
    //                         'max_reward_value' => $promotion->max_reward_value
    //                     ]);
    //                 }

    //                 Log::info("Applying order discount for promotion ID {$promotion->id}", [
    //                     'final_discount' => $discount
    //                 ]);

    //                 $orderDiscountAmount += $discount;

    //                 if ($promotion->promo_mode === 'TIER') break;
    //             }

    //             if ($promotion->promo_type === 'FOC') {

    //                 $tierRewards = $promotion->rewards
    //                     ->where('tier', $tier);

    //                 $totalFreeQty = collect($freeItems)->sum('qty');

    //                 foreach ($tierRewards as $reward) {

    //                     $qty = $reward->reward_qty;

    //                     if ($promotion->promo_mode === 'MULTIPLIER') {
    //                         $qty *= $multiplier;
    //                     }

    //                     if (!is_null($promotion->max_reward_value)) {

    //                         $remainingQty = $promotion->max_reward_value - $totalFreeQty;

    //                         if ($remainingQty <= 0) break;

    //                         $qty = min($qty, $remainingQty);
    //                     }

    //                     $index = collect($freeItems)
    //                         ->search(fn($i) => $i['product_id'] == $reward->product_id);

    //                     if ($index !== false) {
    //                         $freeItems[$index]['qty'] += $qty;
    //                     } else {
    //                         $freeItems[] = [
    //                             'product_id' => $reward->product_id,
    //                             'qty' => $qty
    //                         ];
    //                     }

    //                     $totalFreeQty += $qty;
    //                 }

    //                 if ($promotion->promo_mode === 'TIER') break;
    //             }
    //         }
    //     }

    //     return response()->json([
    //         'product_discounts' => $productDiscounts,
    //         'order_discount_amount' => $orderDiscountAmount,
    //         'free_items' => $freeItems
    //     ]);
    // }

    public function checkPrice(Request $request)
    {
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

        $promotions = Promotion::with(['products', 'conditions', 'rewards'])
            ->where('status_id', 1)
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now())
            ->get();

        $productDiscounts = [];
        $orderDiscountAmount = 0;
        $orderPromotions = [];
        $freeItems = [];

        foreach ($promotions as $promotion) {

            if ($promotion->promo_type === 'PRODUCT_DISCOUNT') {

                foreach ($cartItems as $item) {

                    $hasProduct = $promotion->products
                        ->where('id', $item['product_id'])
                        ->isNotEmpty();

                    if (!$hasProduct) continue;

                    $discount = $promotion->discount_type === 'PERCENT'
                        ? ($item['price'] * $promotion->discount_value) / 100
                        : $promotion->discount_value;

                    $productDiscounts[] = [
                        'product_id' => $item['product_id'],
                        'promotion_id' => $promotion->id,
                        'discount_amount' => $discount,
                        'discount_type' => $promotion->discount_type,
                        'discount_value' => $promotion->discount_value
                    ];
                }

                continue;
            }

            $tiers = $promotion->conditions->groupBy('tier');

            $tiers = $promotion->promo_mode === 'TIER'
                ? $tiers->sortKeysDesc()
                : $tiers->sortKeys();

            foreach ($tiers as $tier => $conditions) {

                $isEligible = true;
                $multipliers = [];

                foreach ($conditions as $condition) {

                    $eligible = false;
                    $multiplier = null;

                    switch ($condition->condition_type) {

                        case 'ITEM_QTY':
                            $qty = $cartItems
                                ->where('product_id', $condition->product_id)
                                ->sum('qty');

                            if ($qty >= $condition->target_value) {
                                $eligible = true;
                                $multiplier = floor($qty / $condition->target_value);
                            }
                            break;

                        case 'ITEM_AMOUNT':
                            $amount = $cartItems
                                ->where('product_id', $condition->product_id)
                                ->sum(fn($i) => $i['qty'] * $i['price']);

                            if ($amount >= $condition->target_value) {
                                $eligible = true;
                                $multiplier = floor($amount / $condition->target_value);
                            }
                            break;

                        case 'ORDER_AMOUNT':
                            if ($totalAmount >= $condition->target_value) {
                                $eligible = true;
                                $multiplier = floor($totalAmount / $condition->target_value);
                            }
                            break;

                        case 'ORDER_QTY':
                            if ($totalQty >= $condition->target_value) {
                                $eligible = true;
                                $multiplier = floor($totalQty / $condition->target_value);
                            }
                            break;
                    }

                    if (!$eligible) {
                        $isEligible = false;
                        break;
                    }

                    if (!is_null($multiplier)) {
                        $multipliers[] = $multiplier;
                    }
                }

                if (!$isEligible) continue;

                $multiplier = count($multipliers) ? min($multipliers) : 1;

                if ($promotion->promo_type === 'ORDER_DISCOUNT') {

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

                    $orderDiscountAmount += $discount;

                    $orderPromotions[] = [
                        'promotion_id' => $promotion->id,
                        'tier' => $tier,
                        'discount' => $discount
                    ];

                    if ($promotion->promo_mode === 'TIER') break;
                }

                if ($promotion->promo_type === 'FOC') {

                    $tierRewards = $promotion->rewards
                        ->where('tier', $tier);

                    $totalFreeQty = collect($freeItems)->sum('qty');

                    foreach ($tierRewards as $reward) {

                        $qty = $reward->reward_qty;

                        if ($promotion->promo_mode === 'MULTIPLIER') {
                            $qty *= $multiplier;
                        }

                        if (!is_null($promotion->max_reward_value)) {
                            $remainingQty = $promotion->max_reward_value - $totalFreeQty;

                            if ($remainingQty <= 0) break;

                            $qty = min($qty, $remainingQty);
                        }

                        // ❗ DO NOT MERGE (important)
                        $freeItems[] = [
                            'product_id' => $reward->product_id,
                            'qty' => $qty,
                            'reward_id' => $reward->id,
                            'promotion_id' => $promotion->id
                        ];

                        $totalFreeQty += $qty;
                    }

                    if ($promotion->promo_mode === 'TIER') break;
                }
            }
        }

        return response()->json([
            'items' => $productDiscounts,
            'order' => [
                'total_discount' => $orderDiscountAmount,
                'applied_promotions' => $orderPromotions
            ],
            'foc_items' => $freeItems
        ]);
    }
    
}
