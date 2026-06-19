<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockTransactionResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            "id" => $this->id,

            "inventory" => [
                "id" => $this->inventory?->id,
                "name" => $this->inventory?->name,
                "qty" => $this->inventory?->qty,
                "expired_date" => $this->inventory?->expired_date ? $this->toLocalDateTime($this->inventory->expired_date) : null,
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
            "reference_date" => $this->reference_date ? $this->toLocalDateTime($this->reference_date) : null,
            "quantity_change" => $this->quantity_change,
            "uom" => [
                "product_unit_id" => $this->product_unit_id,
                "unit_id" => $this->unit_id,
                "unit_name" => $this->unit?->name,
                "unit_quantity" => $this->unit_quantity,
                "base_quantity" => $this->base_quantity,
                "conversion_to_base" => $this->conversion_to_base,
            ],
            "type" => $this->type,
            "reason" => $this->reason,

            "created_by" => [
                "id" => $this->createdBy?->id,
                "name" => $this->createdBy?->name,
            ],

            "created_at" => $this->toLocalDateTime($this->created_at),
        ];
    }
}
