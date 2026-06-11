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
            'allocated_qty' => (int) $this->allocated_qty,
            'used_qty' => (int) $this->used_qty,
            'remaining_qty' => max(0, (int) $this->allocated_qty - (int) $this->used_qty),
            'allocated_warehouse_id' => $this->allocated_warehouse_id,
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];
    }
}