<?php

namespace App\Services;

use App\Models\BranchProduct;
use App\Models\BranchProductUnitPrice;
use App\Models\BranchProductUnitPriceRange;
use App\Models\PriceChange;
use App\Models\PriceChangeProduct;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductUnitPriceRange;
use App\Models\Status;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesPriceChangeEndService
{
    public function processDue(?CarbonInterface $at = null): array
    {
        $at ??= now();
        $appliedStatusId = Status::whereRaw('LOWER(name) = ?', ['applied'])->value('id');
        $endedStatusId = Status::whereRaw('LOWER(name) = ?', ['ended'])->value('id');
        $endedPriceChanges = 0;
        $recalculatedPrices = 0;

        if (! $appliedStatusId || ! $endedStatusId) {
            return compact('endedPriceChanges', 'recalculatedPrices');
        }

        $dueIds = PriceChange::query()
            ->where('type', 'sale')
            ->where('status_id', $appliedStatusId)
            ->whereNull('void_at')
            ->whereNull('ended_at')
            ->whereNotNull('end_at')
            ->where('end_at', '<=', $at)
            ->pluck('id');

        foreach ($dueIds as $id) {
            DB::transaction(function () use (
                $id,
                $at,
                $appliedStatusId,
                $endedStatusId,
                &$endedPriceChanges,
                &$recalculatedPrices
            ) {
                $priceChange = PriceChange::with('products')
                    ->lockForUpdate()
                    ->find($id);

                if (
                    ! $priceChange
                    || (int) $priceChange->status_id !== (int) $appliedStatusId
                    || $priceChange->void_at
                    || $priceChange->ended_at
                    || ! $priceChange->end_at
                    || $priceChange->end_at->isAfter($at)
                ) {
                    return;
                }

                $recalculatedPrices += $this->finalize($priceChange, $at, $endedStatusId);
                $endedPriceChanges++;
            });
        }

        return compact('endedPriceChanges', 'recalculatedPrices');
    }

    public function finalize(
        PriceChange $priceChange,
        CarbonInterface $effectiveAt,
        ?int $endedStatusId = null
    ): int {
        $endedStatusId ??= Status::whereRaw('LOWER(name) = ?', ['ended'])->value('id');

        if (! $endedStatusId) {
            throw new \RuntimeException('The Ended status is not configured.');
        }

        $priceChange->loadMissing('products');
        $priceChange->forceFill([
            'status_id' => $endedStatusId,
            'ended_at' => now(),
        ])->save();

        $recalculated = 0;

        foreach ($priceChange->products as $linkedProduct) {
            if ($this->recalculateTarget($linkedProduct, $effectiveAt)) {
                $recalculated++;
            }
        }

        return $recalculated;
    }

    private function recalculateTarget(PriceChangeProduct $target, CarbonInterface $at): bool
    {
        $priceTarget = $this->lockPriceTarget($target);

        if (! $priceTarget) {
            return false;
        }

        $matchingItems = $this->matchingItems($target);
        $appliedStatusId = Status::whereRaw('LOWER(name) = ?', ['applied'])->value('id');

        $newestActive = (clone $matchingItems)
            ->join('price_changes', 'price_changes.id', '=', 'price_changes_products.price_change_id')
            ->where('price_changes.type', 'sale')
            ->where('price_changes.status_id', $appliedStatusId)
            ->whereNull('price_changes.void_at')
            ->whereNull('price_changes.ended_at')
            ->where(function ($query) use ($at) {
                $query->whereNull('price_changes.start_at')
                    ->orWhere('price_changes.start_at', '<=', $at);
            })
            ->where(function ($query) use ($at) {
                $query->whereNull('price_changes.end_at')
                    ->orWhere('price_changes.end_at', '>', $at);
            })
            ->orderByRaw('COALESCE(price_changes.start_at, price_changes.created_at) DESC')
            ->orderByDesc('price_changes.created_at')
            ->orderByDesc('price_changes_products.id')
            ->select('price_changes_products.*')
            ->first();

        $baseItem = (clone $matchingItems)
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        $desiredPrice = (float) (
            $newestActive?->new_price
            ?? $baseItem?->old_price
            ?? $priceTarget->old_price
            ?? $priceTarget->price
        );

        if ((float) $priceTarget->price === $desiredPrice) {
            return false;
        }

        $priceTarget->price = $desiredPrice;
        $priceTarget->save();

        return true;
    }

    private function matchingItems(PriceChangeProduct $target): Builder
    {
        $query = PriceChangeProduct::query()
            ->where('product_id', $target->product_id);

        $this->whereNullable($query, 'branch_id', $target->branch_id);
        $this->whereNullable($query, 'product_unit_id', $target->product_unit_id);
        $this->whereNullable($query, 'min_qty', $target->min_qty);
        $this->whereNullable($query, 'max_qty', $target->max_qty);

        return $query;
    }

    private function whereNullable(Builder $query, string $column, mixed $value): void
    {
        if ($value === null) {
            $query->whereNull($column);

            return;
        }

        $query->where($column, $value);
    }

    private function lockPriceTarget(PriceChangeProduct $target): mixed
    {
        if ($target->branch_id && $target->product_unit_id && $target->min_qty !== null) {
            return $target->branch_product_unit_price_range_id
                ? BranchProductUnitPriceRange::lockForUpdate()->find($target->branch_product_unit_price_range_id)
                : null;
        }

        if (! $target->branch_id && $target->product_unit_id && $target->min_qty !== null) {
            return $target->product_unit_price_range_id
                ? ProductUnitPriceRange::lockForUpdate()->find($target->product_unit_price_range_id)
                : null;
        }

        if ($target->branch_id && $target->product_unit_id) {
            return $target->branch_product_unit_price_id
                ? BranchProductUnitPrice::lockForUpdate()->find($target->branch_product_unit_price_id)
                : null;
        }

        if ($target->branch_id) {
            return $target->branch_product_id
                ? BranchProduct::lockForUpdate()->find($target->branch_product_id)
                : null;
        }

        if ($target->product_unit_id) {
            return ProductUnit::lockForUpdate()->find($target->product_unit_id);
        }

        return Product::lockForUpdate()->find($target->product_id);
    }
}
