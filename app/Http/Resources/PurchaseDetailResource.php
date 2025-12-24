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

            'inventory' => $this->inventory ? [
                'id' => $this->inventory->id,
                'warehouse_id' => $this->inventory->warehouse_id,
                'expired_date' => $this->inventory->expired_date
            ] : null,

            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'purchase_price' => $this->product->purchase_price,
                'old_purchase_price' => $this->product->old_purchase_price,
                'price' => $this->product->price,
                'old_price' => $this->product->old_price
            ] : null,

            'price'    => $this->price,
            'quantity' => $this->quantity,
            'total'    => $this->total,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
