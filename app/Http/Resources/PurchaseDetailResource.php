<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PurchaseDetailResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,

            'purchase_id' => $this->purchase_id,

            'inventory' => $this->inventory ? [
                'id' => $this->inventory->id,
                'warehouse_id' => $this->inventory->warehouse_id,
                'expired_date' => $this->inventory->expired_date
            ] : null,

            'product' => $this->product ? [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'purchase_price' => $this->product->purchase_price,
                'old_purchase_price' => $this->product->old_purchase_price,
                'price' => $this->product->price,
                'old_price' => $this->product->old_price
            ] : null,

            'price'    => $this->formatPrice($this->price),
            'quantity' => $this->quantity,
            'total'    => $this->total,

            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];
    }

    private function formatPrice($price): int|float|string|null
    {
        if ($price === null) {
            return 0;
        }

        $trimmed = rtrim(rtrim((string) $price, '0'), '.');

        if ($trimmed === '' || $trimmed === '-0') {
            return 0;
        }

        return is_numeric($trimmed) ? $trimmed + 0 : $trimmed;
    }
}
