<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleReturnDetailResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'sale_detail_id' => $this->sale_detail_id,
            'sales_detail' => [
                'id' => $this->saleDetail->id ?? null,
                'inventory_id' => $this->saleDetail->inventory_id ?? null,
                'price' => $this->saleDetail->price ?? null,
                'quantity' => $this->saleDetail->quantity ?? null,
                'total' => $this->saleDetail->total ?? null,
            ],
            'product' => [
                'id' => $this->product->id ?? null,
                'name' => $this->product->name ?? null,
                'price' => $this->price,
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
            'total' => $this->total
        ];
    }
}
