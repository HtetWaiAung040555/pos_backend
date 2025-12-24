<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'unit_id' => [
                'id' => $this->unit->id ?? null,
                'name' => $this->unit->name ?? null
            ],
            'sec_prop'   => $this->sec_prop,

            'category_id' => [
                'id' => $this->category->id ?? null,
                'name' => $this->category->name ?? null
            ],

            'purchase_price' => $this->purchase_price,
            'old_purchase_price' => $this->old_purchase_price,
            'price'      => $this->price,
            'old_price'  => $this->old_price,
            'barcode'    => $this->barcode,
            'image_url'  => $this->image ? url($this->image) : url('assets/img/products/default.png'),
            
            'status' => [
                'id' => $this->status->id ?? null,
                'name' => $this->status->name ?? null,
            ],

            'created_by' => [
                'id' => $this->createdBy->id ?? null,
                'name' => $this->createdBy->name ?? null,
            ],
            
            'updated_by' => [
                'id' => $this->updatedBy->id ?? null,
                'name' => $this->updatedBy->name ?? null,
            ],

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
