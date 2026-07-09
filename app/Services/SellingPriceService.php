<?php

namespace App\Services;

use App\Models\BranchProduct;
use App\Models\BranchProductUnitPrice;
use App\Models\BranchProductUnitPriceRange;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\ProductUnitPriceRange;

class SellingPriceService
{
    public function resolve(
        int $productId,
        ?int $branchId = null,
        ?int $productUnitId = null,
        float $qty = 1,
        ?int $unitId = null
    ): array {
        $product = Product::with('unit')->findOrFail($productId);
        $productUnit = $this->resolveProductUnit($productId, $productUnitId, $unitId);
        $branchProduct = $branchId
            ? BranchProduct::where('branch_id', $branchId)->where('product_id', $productId)->first()
            : null;

        $branchUnitPrice = null;
        $branchRange = null;
        $globalRange = null;

        if ($productUnit) {
            $branchUnitPrice = $branchProduct
                ? BranchProductUnitPrice::where('branch_product_id', $branchProduct->id)
                    ->where('product_unit_id', $productUnit->id)
                    ->first()
                : null;

            if ($branchUnitPrice) {
                $branchRange = $this->matchingBranchRange($branchUnitPrice->id, $qty);
            }

            $globalRange = $this->matchingGlobalRange($productUnit->id, $qty);
        }

        if ($branchRange) {
            return $this->result((float) $branchRange->price, 'BRANCH_UOM_PRICE_RANGE', $product, $productUnit, $branchProduct, $branchUnitPrice, null, $branchRange);
        }

        if ($branchUnitPrice) {
            return $this->result((float) $branchUnitPrice->price, 'BRANCH_UOM_PRICE', $product, $productUnit, $branchProduct, $branchUnitPrice);
        }

        if ($globalRange) {
            return $this->result((float) $globalRange->price, 'GLOBAL_UOM_PRICE_RANGE', $product, $productUnit, $branchProduct, null, $globalRange);
        }

        if ($productUnit) {
            return $this->result((float) $productUnit->price, 'GLOBAL_UOM_PRICE', $product, $productUnit, $branchProduct);
        }

        if ($branchProduct && $branchProduct->price !== null) {
            return $this->result((float) $branchProduct->price, 'BRANCH_PRODUCT_PRICE', $product, null, $branchProduct);
        }

        return $this->result((float) ($product->price ?? 0), 'PRODUCT_PRICE', $product);
    }

    private function resolveProductUnit(int $productId, ?int $productUnitId, ?int $unitId): ?ProductUnit
    {
        if ($productUnitId) {
            return ProductUnit::with('unit')
                ->where('product_id', $productId)
                ->findOrFail($productUnitId);
        }

        if ($unitId) {
            return ProductUnit::with('unit')
                ->where('product_id', $productId)
                ->where('unit_id', $unitId)
                ->first();
        }

        return null;
    }

    private function matchingBranchRange(int $branchUnitPriceId, float $qty): ?BranchProductUnitPriceRange
    {
        return BranchProductUnitPriceRange::where('branch_product_unit_price_id', $branchUnitPriceId)
            ->where('min_qty', '<=', $qty)
            ->where(function ($query) use ($qty) {
                $query->whereNull('max_qty')->orWhere('max_qty', '>=', $qty);
            })
            ->orderByDesc('min_qty')
            ->first();
    }

    private function matchingGlobalRange(int $productUnitId, float $qty): ?ProductUnitPriceRange
    {
        return ProductUnitPriceRange::where('product_unit_id', $productUnitId)
            ->where('min_qty', '<=', $qty)
            ->where(function ($query) use ($qty) {
                $query->whereNull('max_qty')->orWhere('max_qty', '>=', $qty);
            })
            ->orderByDesc('min_qty')
            ->first();
    }

    private function result(
        float $price,
        string $source,
        Product $product,
        ?ProductUnit $productUnit = null,
        ?BranchProduct $branchProduct = null,
        ?BranchProductUnitPrice $branchUnitPrice = null,
        ?ProductUnitPriceRange $globalRange = null,
        ?BranchProductUnitPriceRange $branchRange = null
    ): array {
        return [
            'price' => $price,
            'source' => $source,
            'product_id' => $product->id,
            'product_unit_id' => $productUnit?->id,
            'unit_id' => $productUnit?->unit_id ?? $product->unit_id,
            'unit_name' => $productUnit?->unit?->name ?? $product->unit?->name,
            'conversion_to_base' => $productUnit ? (float) $productUnit->conversion_to_base : 1.0,
            'branch_product_id' => $branchProduct?->id,
            'branch_product_unit_price_id' => $branchUnitPrice?->id,
            'product_unit_price_range_id' => $globalRange?->id,
            'branch_product_unit_price_range_id' => $branchRange?->id,
            'min_qty' => $branchRange?->min_qty ?? $globalRange?->min_qty,
            'max_qty' => $branchRange?->max_qty ?? $globalRange?->max_qty,
        ];
    }
}
