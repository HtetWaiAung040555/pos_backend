<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'name'       => $this->name,
            'qty'        => $this->qty,
            'expired_date' => $this->expired_date ? $this->toLocalDateTime($this->expired_date) : null,
            
            'product'    => $this->product ? [
                'id'       => $this->product->id,
                'name'     => $this->product->name,
                'barcode'  => $this->product->barcode,
                'image_url'=> $this->product->image ? url($this->product->image) : url('assets/img/products/default.png'),
                'price'    => $this->product->price,
                'unit'     => $this->product->unit,
                'sec_prop' => $this->product->sec_prop
            ] : null,

            'warehouse'     => $this->warehouse ? [
                'id'   => $this->warehouse->id,
                'name' => $this->warehouse->name,
            ] : null,

            'created_by' => $this->createdBy?->name,
            'updated_by' => $this->updatedBy?->name,
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];
    }
}
