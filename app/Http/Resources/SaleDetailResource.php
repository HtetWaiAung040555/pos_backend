<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SaleDetailResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'product' => [
                'id' => $this->product->id ?? null,
                'name' => $this->product->name ?? null,
                'price' => $this->price,
            ],
            'price' => $this->price,
            'quantity' => $this->quantity,
            'discount_price' => $this->discount_price,
            'discount_amount' => $this->discount_amount,
            'total' => $this->total,
            'promotion' => [
                'id' => $this->promotion->id ?? null,
                'name' => $this->promotion->name ?? null,
                'discount_type' => $this->promotion->discount_type ?? null,
                'discount_value' => $this->promotion->discount_value ?? null,
                'start_at' => $this->promotion->start_at ?? null,
                'end_at' => $this->promotion->end_at ?? null,
                'status' => [
                    'id' => $this->promotion->status->id ?? null,
                    'name' => $this->promotion->status->name ?? null,
                ],
                'created_by' => $this->promotion->createdBy->name ?? null,
            ],
        ];
    }
}
