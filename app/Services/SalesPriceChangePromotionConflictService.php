<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Promotion;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

class SalesPriceChangePromotionConflictService
{
    private const BLOCKING_PROMOTION_TYPES = [
        'PRODUCT_DISCOUNT',
        'PRICE_OVERRIDE',
        'FOC',
    ];

    public function check(array $items, CarbonInterface $startAt, ?CarbonInterface $endAt = null): array
    {
        $promotions = Promotion::with([
            'products',
            'conditions',
            'rewards',
            'branches',
            'warehouses',
        ])
            ->whereNull('void_at')
            ->where('end_at', '>=', $startAt)
            ->when($endAt, fn ($query) => $query->where('start_at', '<=', $endAt))
            ->get();

        $branchWarehouseIds = Branch::query()
            ->whereIn('id', collect($items)->pluck('branch_id')->filter()->unique())
            ->pluck('warehouse_id', 'id');

        $now = now();
        $conflicts = collect($items)
            ->flatMap(function (array $item) use ($promotions, $branchWarehouseIds, $now) {
                return $promotions->map(function (Promotion $promotion) use ($item, $branchWarehouseIds, $now) {
                    if (! $this->scopeOverlaps($promotion, $item, $branchWarehouseIds)) {
                        return null;
                    }

                    $matchedAs = $this->matchedAs($promotion, $item);

                    if ($matchedAs === []) {
                        return null;
                    }

                    $blocking = in_array($promotion->promo_type, self::BLOCKING_PROMOTION_TYPES, true);
                    $promotionStart = Carbon::parse($promotion->start_at);
                    $promotionEnd = Carbon::parse($promotion->end_at);

                    return [
                        'product_id' => (int) $item['product_id'],
                        'branch_id' => isset($item['branch_id']) ? (int) $item['branch_id'] : null,
                        'product_unit_id' => isset($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
                        'promotion' => [
                            'id' => (int) $promotion->id,
                            'name' => $promotion->name,
                            'promo_type' => $promotion->promo_type,
                            'start_at' => $this->formatDateTime($promotionStart),
                            'end_at' => $this->formatDateTime($promotionEnd),
                        ],
                        'matched_as' => $matchedAs,
                        'currently_ongoing' => $promotionStart->lte($now) && $promotionEnd->gte($now),
                        'blocking' => $blocking,
                        'reason' => $this->reason($promotion, $blocking),
                    ];
                })->filter();
            })
            ->values();

        $blockingConflicts = $conflicts->where('blocking', true);
        $latestBlockingEnd = $blockingConflicts
            ->map(fn (array $conflict) => Carbon::parse($conflict['promotion']['end_at']))
            ->sortDesc()
            ->first();

        return [
            'has_ongoing_promotion' => $conflicts->contains('currently_ongoing', true),
            'has_promotion_overlap' => $conflicts->isNotEmpty(),
            'has_conflict' => $blockingConflicts->isNotEmpty(),
            'can_apply_price_change' => $blockingConflicts->isEmpty(),
            'conflicts' => $conflicts->all(),
            'suggested_start_at' => $latestBlockingEnd
                ? $this->formatDateTime($latestBlockingEnd->addSecond())
                : null,
        ];
    }

    private function scopeOverlaps(Promotion $promotion, array $item, Collection $branchWarehouseIds): bool
    {
        $branchId = $item['branch_id'] ?? null;

        // A global price change can overlap any selected promotion scope.
        if (! $branchId) {
            $hasBranchScope = $promotion->branch_scope_type !== 'SELECTED' || $promotion->branches->isNotEmpty();
            $hasWarehouseScope = $promotion->warehouse_scope_type !== 'SELECTED' || $promotion->warehouses->isNotEmpty();

            return $hasBranchScope && $hasWarehouseScope;
        }

        if (
            $promotion->branch_scope_type === 'SELECTED'
            && ! $promotion->branches->contains('id', (int) $branchId)
        ) {
            return false;
        }

        if ($promotion->warehouse_scope_type !== 'SELECTED') {
            return true;
        }

        $warehouseId = $branchWarehouseIds->get((int) $branchId);

        return $warehouseId
            && $promotion->warehouses->contains('id', (int) $warehouseId);
    }

    private function matchedAs(Promotion $promotion, array $item): array
    {
        if ($promotion->promo_type === 'ORDER_DISCOUNT') {
            return ['order_wide'];
        }

        $matchedAs = [];

        if ($promotion->promo_type !== 'FOC' && $promotion->products->isEmpty()) {
            $matchedAs[] = 'all_products';
        } elseif ($this->productCollectionMatches($promotion->products, $item)) {
            $matchedAs[] = 'promotion_product';
        }

        if ($promotion->promo_type === 'FOC') {
            if ($this->productCollectionMatches($promotion->conditions, $item)) {
                $matchedAs[] = 'condition_product';
            }

            if ($this->productCollectionMatches($promotion->rewards, $item)) {
                $matchedAs[] = 'reward_product';
            }
        }

        return array_values(array_unique($matchedAs));
    }

    private function productCollectionMatches(Collection $products, array $item): bool
    {
        return $products->contains(function ($product) use ($item) {
            $promotionProductId = $product->pivot
                ? $product->id
                : $product->product_id;

            if ((int) $promotionProductId !== (int) $item['product_id']) {
                return false;
            }

            $promotionProductUnitId = $product->pivot?->product_unit_id
                ?? $product->product_unit_id
                ?? null;

            return ! $promotionProductUnitId
                || (isset($item['product_unit_id']) && (int) $promotionProductUnitId === (int) $item['product_unit_id']);
        });
    }

    private function reason(Promotion $promotion, bool $blocking): string
    {
        if (! $blocking) {
            return "Order discount promotion {$promotion->id} overlaps this price-change period but does not block it.";
        }

        return "Product is involved in {$promotion->promo_type} promotion {$promotion->id} during this price-change period.";
    }

    private function formatDateTime(CarbonInterface $value): string
    {
        return $value->copy()
            ->timezone(config('app.timezone'))
            ->toDateTimeString();
    }
}
