<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = [
            'id' => $this->id,
            'description' => $this->description,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'status' => $this->status ? [
                'id'   => $this->status->id,
                'name' => $this->status->name,
            ] : null,
            'void_at' => optional($this->void_at)->toDateTimeString(),
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
                    'image_url' => $product->image ? asset($this->image) : null,
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
