<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SaleDetailResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,

            'sale_id' => $this->sale_id,

            'inventory_id' => $this->inventory_id, 

            'product' => [
                'id' => $this->product->id ?? null,
                'name' => $this->product->name ?? null,
                'price' => $this->price,
                'barcode' => $this->product->barcode ?? null,
            ],

            'price' => $this->price,
            'quantity' => $this->quantity,
            'uom' => [
                'product_unit_id' => $this->product_unit_id,
                'unit_id' => $this->unit_id,
                'unit_name' => $this->unit_name,
                'unit_quantity' => $this->unit_quantity,
                'base_quantity' => $this->base_quantity,
                'conversion_to_base' => $this->conversion_to_base,
                'unit_barcode' => $this->unit_barcode,
                'price_range_id' => $this->price_range_id,
            ],
            'discount_price' => $this->discount_price,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'is_foc' => $this->is_foc,
            'reward_id' => $this->reward_id,
            'promotion' => [
                'id' => $this->promotion->id ?? null,
                'name' => $this->promotion->name ?? null,
                'discount_type' => $this->promotion->discount_type ?? null,
                'discount_value' => $this->promotion->discount_value ?? null,
                'start_at' => $this->promotion->start_at ?? null,
                'end_at' => $this->promotion->end_at ?? null,
                'status' => [
                    'id' => $this->promotion->status->id ?? null,
                    'name' => $this->promotion->status->name ?? null
                ],
                'created_by' => $this->promotion->createdBy->name ?? null
            ],
        ];
    }
}
