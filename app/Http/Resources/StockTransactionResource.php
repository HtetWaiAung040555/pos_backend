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
                "expired_date" => $this->inventory?->expired_date,
                "product" => $this->inventory?->product
                    ? [
                        "id" => $this->inventory->product->id,
                        "name" => $this->inventory->product->name,
                        "unit" => $this->inventory->product->unit,
                        "price" => $this->inventory->product->price,
                        "barcode" => $this->inventory->product->barcode,
                        "image_url" => $this->inventory->product->image ? url($this->inventory->product->image) : url('assets/img/products/default.png'),
                    ]
                    : null,

                "warehouse" => $this->inventory?->warehouse
                    ? [
                        "id" => $this->inventory->warehouse->id,
                        "name" => $this->inventory->warehouse->name,
                    ]
                    : null,
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
