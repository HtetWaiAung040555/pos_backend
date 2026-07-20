<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray($request)
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'promo_type' => $this->promo_type,
            'condition_type' => $this->condition_type,
            'promo_mode' => $this->promo_mode,
            'max_reward_value' => $this->max_reward_value,
            'override_price' => $this->override_price,
            'branch_scope_type' => $this->branch_scope_type,
            'warehouse_scope_type' => $this->warehouse_scope_type,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'start_at' => $this->toLocalDateTime($this->start_at),
            'end_at' => $this->toLocalDateTime($this->end_at),
            'status' => $this->status ? [
                'id' => $this->status->id,
                'name' => $this->status->name,
            ] : null,
            'void_at' => $this->toLocalDateTime($this->void_at),
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,

            'updated_by' => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ] : null,

            'void_by' => $this->voidBy ? [
                'id' => $this->voidBy->id,
                'name' => $this->voidBy->name,
            ] : null,
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
            'conditions' => PromotionConditionResource::collection($this->whenLoaded('conditions')),
            'rewards' => PromotionRewardResource::collection($this->whenLoaded('rewards')),
        ];

        if ($this->relationLoaded('focAllocations')) {
            $data['foc_allocations'] = $this->focAllocations->map(function ($allocation) {
                $allocatedBaseQty = (float) ($allocation->allocated_base_qty ?? $allocation->allocated_qty ?? 0);
                $usedBaseQty = (float) ($allocation->used_base_qty ?? $allocation->used_qty ?? 0);

                return [
                    'id' => $allocation->id,
                    'promotion_id' => $allocation->promotion_id,
                    'branch_id' => $allocation->branch_id,
                    'branch' => $allocation->relationLoaded('branch') && $allocation->branch ? [
                        'id' => $allocation->branch->id,
                        'name' => $allocation->branch->name,
                    ] : null,
                    'allocated_warehouse_id' => $allocation->allocated_warehouse_id,
                    'warehouse' => $allocation->relationLoaded('warehouse') && $allocation->warehouse ? [
                        'id' => $allocation->warehouse->id,
                        'name' => $allocation->warehouse->name,
                    ] : null,
                    'product_id' => $allocation->product_id,
                    'product_unit_id' => $allocation->product_unit_id,
                    'unit_id' => $allocation->unit_id,
                    'uom' => [
                        'product_unit_id' => $allocation->product_unit_id,
                        'unit_id' => $allocation->unit_id,
                        'unit_name' => $allocation->unit_name,
                        'unit_quantity' => $allocation->unit_quantity,
                        'base_quantity' => $allocation->base_quantity,
                        'conversion_to_base' => $allocation->conversion_to_base,
                    ],
                    'product' => $allocation->product ? [
                        'id' => $allocation->product->id,
                        'name' => $allocation->product->name,
                        'image_url' => $allocation->product->image ? url($allocation->product->image) : url('assets/img/products/default.png'),
                        'barcode' => $allocation->product->barcode,
                        'price' => $allocation->product->price,
                    ] : null,
                    'allocated_qty' => (int) $allocation->allocated_qty,
                    'allocated_base_qty' => $allocatedBaseQty,
                    'used_qty' => (int) $allocation->used_qty,
                    'used_base_qty' => $usedBaseQty,
                    'remaining_qty' => max(0, (int) $allocation->allocated_qty - (int) $allocation->used_qty),
                    'remaining_base_qty' => max(0, $allocatedBaseQty - $usedBaseQty),
                ];
            })->values();
        }

        if ($this->relationLoaded('products')) {
            $data['products'] = $this->products->map(function ($product) {
                return [
                    'id' => $product->id,
                    'name' => $product->name,
                    'unit' => $product->unit,
                    'sec_prop' => $product->sec_prop,
                    'price' => $product->price,
                    'barcode' => $product->barcode,
                    'promotion_product_unit_id' => $product->pivot->product_unit_id ?? null,
                    'promotion_unit_id' => $product->pivot->unit_id ?? null,
                    'max_qty_per_sales_order' => $product->pivot->max_qty_per_sales_order !== null
                        ? (float) $product->pivot->max_qty_per_sales_order
                        : null,
                    'image_url' => $product->image ? url($product->image) : url('assets/img/products/default.png'),
                    'status' => $product->status ? [
                        'id' => $product->status->id,
                        'name' => $product->status->name,
                    ] : null,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                    ] : null,
                ];
            });
        }

        if ($this->relationLoaded('branches')) {
            $data['branches'] = $this->branches->map(function ($branch) {
                return [
                    'id' => $branch->id,
                    'name' => $branch->name,
                ];
            })->values();
        }

        if ($this->relationLoaded('warehouses')) {
            $data['warehouses'] = $this->warehouses->map(function ($warehouse) {
                return [
                    'id' => $warehouse->id,
                    'name' => $warehouse->name,
                ];
            })->values();
        }

        $data['active'] = $this->start_at && $this->end_at
            ? now()->between($this->start_at, $this->end_at)
            : false;

        return $data;
    }
}
