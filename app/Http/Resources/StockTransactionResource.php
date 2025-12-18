<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,

            "inventory" => [
                "id" => $this->inventory?->id,
                "name" => $this->inventory?->name,
                "qty" => $this->inventory?->qty,
                "product_id" => $this->inventory?->product_id,
                "warehouse_id" => $this->inventory?->warehouse_id,
            ],

            "reference_id" => $this->reference_id,
            "reference_type" => $this->reference_type,
            "quantity_change" => $this->quantity_change,
            "type" => $this->type,
            "reason" => $this->reason,

            "created_by" => [
                "id" => $this->createdBy?->id,
                "name" => $this->createdBy?->name,
            ],
            
            "created_at" => $this->created_at->toDateTimeString(),
        ];
    }
}
