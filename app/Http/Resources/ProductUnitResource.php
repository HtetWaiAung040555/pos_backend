<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductUnitResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
            ]),
            'unit_id' => [
                'id' => $this->unit->id ?? null,
                'name' => $this->unit->name ?? null,
            ],
            'barcode' => $this->barcode,
            'conversion_to_base' => $this->conversion_to_base,
            'price' => $this->price,
            'old_price' => $this->old_price,
            'purchase_price' => $this->purchase_price,
            'old_purchase_price' => $this->old_purchase_price,
            'is_base_unit' => $this->is_base_unit,
            'is_default_sale_unit' => $this->is_default_sale_unit,
            'sort_order' => $this->sort_order,
            'price_ranges' => ProductUnitPriceRangeResource::collection($this->whenLoaded('priceRanges')),

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
