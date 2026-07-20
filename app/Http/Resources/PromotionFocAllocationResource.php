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
        $allocatedBaseQty = (float) ($this->allocated_base_qty ?? $this->allocated_qty ?? 0);
        $usedBaseQty = (float) ($this->used_base_qty ?? $this->used_qty ?? 0);

        return [
            'id' => $this->id,
            'promotion_id' => $this->promotion_id,
            'branch_id' => $this->branch_id,
            'branch' => $this->whenLoaded('branch', fn () => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
            ] : null),
            'allocated_warehouse_id' => $this->allocated_warehouse_id,
            'warehouse' => $this->whenLoaded('warehouse', fn () => $this->warehouse ? [
                'id' => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ] : null),
            'product_id' => $this->product_id,
            'product_unit_id' => $this->product_unit_id,
            'unit_id' => $this->unit_id,
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
            'allocated_base_qty' => $allocatedBaseQty,
            'used_qty' => (int) $this->used_qty,
            'used_base_qty' => $usedBaseQty,
            'remaining_qty' => max(0, (int) $this->allocated_qty - (int) $this->used_qty),
            'remaining_base_qty' => max(0, $allocatedBaseQty - $usedBaseQty),
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];
    }
}
