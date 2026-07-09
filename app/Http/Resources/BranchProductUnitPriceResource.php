<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchProductUnitPriceResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_product_id' => $this->branch_product_id,
            'branch_product' => new BranchProductResource($this->whenLoaded('branchProduct')),
            'product_unit_id' => $this->product_unit_id,
            'product_unit' => $this->whenLoaded('productUnit', fn () => $this->productUnit ? [
                'id' => $this->productUnit->id,
                'product_id' => $this->productUnit->product_id,
                'unit_id' => $this->productUnit->unit_id,
                'unit_name' => $this->productUnit->unit->name ?? $this->unit_name,
                'barcode' => $this->productUnit->barcode,
                'conversion_to_base' => $this->productUnit->conversion_to_base,
            ] : null),
            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit ? [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
            ] : null),
            'unit_name' => $this->unit_name,
            'conversion_to_base' => $this->conversion_to_base,
            'price' => $this->price,
            'old_price' => $this->old_price,
            'price_ranges' => BranchProductUnitPriceRangeResource::collection($this->whenLoaded('priceRanges')),
            'status' => [
                'id' => $this->status->id ?? null,
                'name' => $this->status->name ?? null,
            ],
            'created_by' => [
                'id' => $this->createdBy->id ?? null,
                'name' => $this->createdBy->name ?? null,
            ],
            'updated_by' => [
                'id' => $this->updatedBy->id ?? null,
                'name' => $this->updatedBy->name ?? null,
            ],
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];
    }
}
