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
            'total' => $this->total
        ];
    }
}
