<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray($request)
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'start_at' => $this->toLocalDateTime($this->start_at),
            'end_at' => $this->toLocalDateTime($this->end_at),
            'status' => $this->status ? [
                'id'   => $this->status->id,
                'name' => $this->status->name,
            ] : null,
            'void_at' => $this->toLocalDateTime($this->void_at),
            'void_by' => $this->voidByUser ? [
                'id' => $this->voidByUser->id,
                'name' => $this->voidByUser->name,
            ] : null,
            'created_by' => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ],
            'updated_by' => [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ],
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];

        if ($this->relationLoaded('products')) {
            $data['products'] = $this->products->map(function ($product) {
                return [
                    'id'    => $product->id,
                    'name'  => $product->name,
                    'unit'  => $product->unit,
                    'sec_prop' => $product->sec_prop,
                    'price' => $product->price,
                    'barcode' => $product->barcode,
                    'image_url' => $product->image ? url($this->image) : url('assets/img/products/default.png'),
                    'status' => $product->status ? [
                        'id'   => $product->status->id,
                        'name' => $product->status->name,
                    ] : null,
                    'category' => $product->category ? [
                        'id' => $product->category->id,
                        'name' => $product->category->name,
                    ] : null,
                ];
            });
        }

        $data['active'] = $this->start_at && $this->end_at
            ? now()->between($this->start_at, $this->end_at)
            : false;

        return $data;
    }
}
