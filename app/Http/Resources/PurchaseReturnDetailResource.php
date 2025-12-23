<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchase_detail_id' => $this->purchase_detail_id,
            'purchases_detail' => [
                'id' => $this->purchaseDetail->id ?? null,
                'inventory_id' => $this->purchaseDetail->inventory_id ?? null,
                'price' => $this->purchaseDetail->price ?? null,
                'quantity' => $this->purchaseDetail->quantity ?? null,
                'total' => $this->purchaseDetail->total ?? null,
            ],
            'product' => [
                'id' => $this->product->id ?? null,
                'name' => $this->product->name ?? null,
                'price' => $this->product->purchase_price,
            ],
            'price' => $this->price,
            'quantity' => $this->quantity,
            'total' => $this->total
        ];
    }
}
