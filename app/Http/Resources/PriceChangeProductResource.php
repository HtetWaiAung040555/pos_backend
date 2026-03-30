<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PriceChangeProductResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'price_change_id' => $this->price_change_id, 
            'product' => [
                'id'    => $this->product->id,
                    'name'  => $this->product->name,
                    'unit'  => $this->product->unit ? [
                        'id' => $this->product->unit->id,
                        'name' => $this->product->unit->name,
                    ] : null,
                    'purchase_price' => $this->product->purchase_price,
                    'old_purchase_price' => $this->product->old_purchase_price,
                    'price' => $this->product->price,
                    'old_price' => $this->product->old_price,
                    'barcode' => $this->product->barcode,
                    'image_url' => $this->product->image ? url($this->product->image) : url('assets/img/products/default.png'),
                    'category' => $this->product->category ? [
                        'id' => $this->product->category->id,
                        'name' => $this->product->category->name,
                    ] : null,
            ],
            'old_price' => $this->old_price,
            'new_price' => $this->new_price,
        ];
    }
}
