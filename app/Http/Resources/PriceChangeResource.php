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
            'type' => $this->type,
            'start_at' => $this->start_at,
            'end_at' => $this->end_at,
            'status' => $this->status ? [
                'id'   => $this->status->id,
                'name' => $this->status->name,
            ] : null,
            'void_at' => optional($this->void_at)->toDateTimeString(),
            'void_by' => $this->voidBy ? [
                'id' => $this->voidBy->id,
                'name' => $this->voidBy->name,
            ] : null,
            'created_by' => $this->createdBy ? [
                'id' => $this->createdBy->id,
                'name' => $this->createdBy->name,
            ] : null,
            'updated_by' => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'name' => $this->updatedBy->name,
            ] : null,
            'created_at' => optional($this->created_at)->toDateTimeString(),
            'updated_at' => optional($this->updated_at)->toDateTimeString(),
        ];

        if ($this->relationLoaded('products')) {
            $data['products'] = $this->products->map(function ($product) {
                return [
                    'id'    => $product->id,
                    'name'  => $product->name,
                    'unit'  => $product->unit ? [
                        'id' => $product->unit->id,
                        'name' => $product->unit->name,
                    ] : null,
                    'sec_prop' => $product->sec_prop,
                    'purchase_price' => $product->purchase_price,
                    'old_purchase_price' => $product->old_purchase_price,
                    'price' => $product->price,
                    'old_price' => $product->old_price,
                    'barcode' => $product->barcode,
                    'image_url' => $product->image ? url($product->image) : url('assets/img/products/default.png'),
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

        $data['active'] = $this->start_at && $this->end_at && !$this->void_at
            ? now()->between($this->start_at, $this->end_at)
            : false;

        return $data;
    }
}
