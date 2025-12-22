<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'purchase_id' => $this->purchase_id,

            'inventory' => $this->inventory_id,

            'product' => [
                'id'   => $this->product->id,
                'name' => $this->product->name,
                'price' => $this->price
            ],

            'price'    => $this->price,
            'quantity' => $this->quantity,
            'total'    => $this->total,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
