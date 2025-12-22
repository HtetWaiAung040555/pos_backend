<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'warehouse' => [
                'id' => $this->warehouse->id ?? null,
                'name' => $this->warehouse->name ?? null,
            ],
            'supplier' => [
                'id' => $this->supplier->id ?? null,
                'name' => $this->supplier->name ?? null,
                'balance' => $this->supplier->balance ?? 0,
            ],
            'payment_method' => [
                'id' => $this->paymentMethod->id ?? null,
                'name' => $this->paymentMethod->name ?? null,
            ],
            'status' => [
                'id' => $this->status->id ?? null,
                'name' => $this->status->name ?? null,
            ],
            'total_amount' => $this->total_amount,
            'remark' => $this->remark,
            'purchase_date' => $this->purchase_date,
            'created_by' => $this->createdBy->name ?? null,
            'updated_by' => $this->updatedBy->name ?? null,
            'details' => PurchaseDetailResource::collection($this->whenLoaded('details')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
