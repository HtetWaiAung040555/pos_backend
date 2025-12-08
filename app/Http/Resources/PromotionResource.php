<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PromotionResource extends JsonResource
{
    public function toArray($request)
    {
        $data = [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'discount_type' => $this->discount_type,
            'discount_value' => $this->discount_value,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'created_by' => [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ],
            'updated_by' => [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ],
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
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
                    'image_url' => $product->image_url,
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
