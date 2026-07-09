<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PriceChangeProductResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'price_change_id' => $this->price_change_id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'branch_product_id' => $this->branch_product_id,
            'branch_product' => new BranchProductResource($this->whenLoaded('branchProduct')),
            'product' => [
                'id'    => $this->product->id,
                    'name'  => $this->product->name,
                    'unit'  => $this->product->unit ? [
                        'id' => $this->product->unit->id,
                        'name' => $this->product->unit->name,
                    ] : null,
                    'purchase_price' => $this->product->purchase_price,
                    'old_purchase_price' => $this->product->old_purchase_price,
                    'price' => $this->product->price,
                    'old_price' => $this->product->old_price,
                    'barcode' => $this->product->barcode,
                    'image_url' => $this->product->image ? url($this->product->image) : url('assets/img/products/default.png'),
                    'category' => $this->product->category ? [
                        'id' => $this->product->category->id,
                        'name' => $this->product->category->name,
                    ] : null,
            ],
            'product_unit_id' => $this->product_unit_id,
            'branch_product_unit_price_id' => $this->branch_product_unit_price_id,
            'branch_product_unit_price' => new BranchProductUnitPriceResource($this->whenLoaded('branchProductUnitPrice')),
            'product_unit_price_range_id' => $this->product_unit_price_range_id,
            'product_unit_price_range' => new ProductUnitPriceRangeResource($this->whenLoaded('productUnitPriceRange')),
            'branch_product_unit_price_range_id' => $this->branch_product_unit_price_range_id,
            'branch_product_unit_price_range' => new BranchProductUnitPriceRangeResource($this->whenLoaded('branchProductUnitPriceRange')),
            'product_unit' => $this->whenLoaded('productUnit', fn () => $this->productUnit ? [
                'id' => $this->productUnit->id,
                'unit_id' => $this->productUnit->unit_id,
                'unit_name' => $this->productUnit->unit->name ?? $this->unit_name,
                'barcode' => $this->productUnit->barcode,
                'conversion_to_base' => $this->productUnit->conversion_to_base,
                'price' => $this->productUnit->price,
            ] : null),
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
            ] : null),
            'unit_name' => $this->unit_name,
            'conversion_to_base' => $this->conversion_to_base,
            'min_qty' => $this->min_qty,
            'max_qty' => $this->max_qty,
            'old_price' => $this->old_price,
            'new_price' => $this->new_price,
        ];
    }
}
