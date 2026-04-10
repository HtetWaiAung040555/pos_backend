<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionRewardResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'promotion_id' => $this->promotion_id,
            'tier' => $this->tier,
            'reward_type' => $this->reward_type,
            'reward_value' => $this->reward_value,
            'reward_qty' => $this->reward_qty,
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