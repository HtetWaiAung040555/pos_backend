<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionConditionResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promotion_id' => $this->promotion_id,
            'group_no' => $this->group_no,
            'tier' => $this->tier,
            'condition_type' => $this->condition_type,
            'operator' => $this->operator,
            'target_value' => $this->target_value,
            'target_value_to' => $this->target_value_to,
            'uom' => [
                'product_unit_id' => $this->product_unit_id,
                'unit_id' => $this->unit_id,
                'unit_name' => $this->unit_name,
                'conversion_to_base' => $this->conversion_to_base,
                'product_unit' => $this->productUnit ? [
                    'id' => $this->productUnit->id,
                    'barcode' => $this->productUnit->barcode,
                    'price' => $this->productUnit->price,
                    'purchase_price' => $this->productUnit->purchase_price,
                    'is_base_unit' => $this->productUnit->is_base_unit,
                    'is_default_sale_unit' => $this->productUnit->is_default_sale_unit,
                ] : null,
            ],
            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'image_url' => $this->product->image ? url($this->product->image) : url('assets/img/products/default.png'),
                'barcode' => $this->product->barcode,
                'price' => $this->product->price,
            ] : null,
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];
    }
}
