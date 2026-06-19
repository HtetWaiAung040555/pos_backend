<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionFocAllocationResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promotion_id' => $this->promotion_id,
            'product_id' => $this->product_id,
            'uom' => [
                'product_unit_id' => $this->product_unit_id,
                'unit_id' => $this->unit_id,
                'unit_name' => $this->unit_name,
                'unit_quantity' => $this->unit_quantity,
                'base_quantity' => $this->base_quantity,
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
            'allocated_qty' => (int) $this->allocated_qty,
            'used_qty' => (int) $this->used_qty,
            'remaining_qty' => max(0, (int) $this->allocated_qty - (int) $this->used_qty),
            'allocated_warehouse_id' => $this->allocated_warehouse_id,
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];
    }
}
