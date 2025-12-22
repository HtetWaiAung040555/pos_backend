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
                'id'   => optional($this->warehouse)->id,
                'name' => optional($this->warehouse)->name,
            ],

            'supplier' => [
                'id'      => optional($this->supplier)->id,
                'name'    => optional($this->supplier)->name,
            ],

            'payment' => [
                'id'   => optional($this->payment)->id,
                'name' => optional($this->payment)->name,
            ],

            'status' => [
                'id'   => optional($this->status)->id,
                'name' => optional($this->status)->name,
            ],

            'total_amount'  => $this->total_amount,
            'remark'        => $this->remark,
            'purchase_date' => $this->purchase_date,

            'created_by' => optional($this->createdBy)->name,
            'updated_by' => optional($this->updatedBy)->name,

            'void_by' => optional($this->voidBy)->name,
            'void_at' => $this->void_at,

            'details' => PurchaseDetailResource::collection(
                $this->whenLoaded('details')
            ),

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
