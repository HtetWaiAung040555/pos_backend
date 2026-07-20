<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceChangeResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        $now = now();
        $statusName = strtolower((string) $this->status?->name);
        $started = ! $this->start_at || $this->start_at->lte($now);
        $pastEnd = $this->end_at && $this->end_at->lte($now);
        $ended = $statusName === 'ended' || $this->ended_at || $this->void_at || $pastEnd;

        if ($ended) {
            $effectiveState = 'ended';
        } elseif (! $started || $statusName !== 'applied') {
            $effectiveState = 'scheduled';
        } elseif ($this->ended_by && $this->end_at?->gt($now)) {
            $effectiveState = 'ending_scheduled';
        } else {
            $effectiveState = 'ongoing';
        }

        $canEnd = $this->type === 'sale'
            && $statusName === 'applied'
            && $started
            && ! $pastEnd
            && ! $this->void_at
            && ! $this->ended_at
            && ! $this->ended_by;

        $data = [
            'id' => $this->id,
            'description' => $this->description,
            'type' => $this->type,
            'start_at' => $this->toLocalDateTime($this->start_at),
            'end_at' => $this->toLocalDateTime($this->end_at),
            'ended_at' => $this->toLocalDateTime($this->ended_at),
            'ended_by' => $this->endedBy ? [
                'id' => $this->endedBy->id,
                'name' => $this->endedBy->name,
            ] : null,
            'end_reason' => $this->end_reason,
            'can_end' => $canEnd,
            'effective_state' => $effectiveState,
            'status' => $this->status ? [
                'id' => $this->status->id,
                'name' => $this->status->name,
            ] : null,
            'products' => PriceChangeProductResource::collection($this->whenLoaded('products')),
            'void_at' => $this->toLocalDateTime($this->void_at),
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
            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),

        ];

        // if ($this->relationLoaded('products')) {
        //     $data['products'] = $this->products->map(function ($product) {
        //         return [
        //             'id'    => $product->id,
        //             'name'  => $product->name,
        //             'unit'  => $product->unit ? [
        //                 'id' => $product->unit->id,
        //                 'name' => $product->unit->name,
        //             ] : null,
        //             'purchase_price' => $product->purchase_price,
        //             'old_purchase_price' => $product->old_purchase_price,
        //             'price' => $product->price,
        //             'old_price' => $product->old_price,
        //             'barcode' => $product->barcode,
        //             'image_url' => $product->image ? url($product->image) : url('assets/img/products/default.png'),
        //             'category' => $product->category ? [
        //                 'id' => $product->category->id,
        //                 'name' => $product->category->name,
        //             ] : null,
        //         ];
        //     });
        // }

        $data['active'] = in_array($effectiveState, ['ongoing', 'ending_scheduled'], true);

        return $data;
    }
}
