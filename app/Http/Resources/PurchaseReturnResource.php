<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseReturnResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            
            'purchases' => [
                'id' => $this->purchase->id ?? null,
                'total_amount' => $this->purchase->total_amount ?? null,
                'purchase_date' => $this->purchase->purchase_date ?? null,
            ],

            'warehouse' => [
                'id' => $this->warehouse->id ?? null,
                'name' => $this->warehouse->name ?? null,
            ],

            'supplier' => [
                'id' => $this->supplier->id ?? null,
                'name' => $this->supplier->name ?? null,
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
            'remark'       => $this->remark,
            'return_date'  => $this->return_date,

            'details' => SaleReturnDetailResource::collection(
                $this->whenLoaded('details')
            ),

            'created_by' => [
                'id' => $this->createdBy->id ?? null,
                'name' => $this->createdBy->name ?? null,
            ],

            'updated_by' => [
                'id' => $this->updatedBy->id ?? null,
                'name' => $this->updatedBy->name ?? null,
            ],

            'void' => [
                'void_at' => $this->void_at,
                'void_by' => [
                    'id' => $this->voidBy->id ?? null,
                    'name' => $this->voidBy->name ?? null,
                ],
            ],

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
