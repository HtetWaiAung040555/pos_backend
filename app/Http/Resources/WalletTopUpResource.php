<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WalletTopUpResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'customer_id' => $this->customer_id,
            'type'        => $this->type ?? 'deposit',
            'amount'      => $this->amount,

            'payment_method' => $this->paymentMethod ? [
                'id'   => $this->paymentMethod->id,
                'name' => $this->paymentMethod->name,
            ] : null,

            'status' => [
                'id' => $this->status->id ?? null,
                'name' => $this->status->name ?? null,
            ],

            'customer' => $this->customer ? [
                'id'        => $this->customer->id,
                'name'      => $this->customer->name,
                'phone'     => $this->customer->phone ?? null,
                'address'   => $this->customer->address ?? null,
                'payable'   => $this->customer->payable ?? null,
                'paid'      => $this->customer->paid_amount ?? null,
                'total'     => $this->customer->total ?? null,
            ] : null,

            'remark'     => $this->remark,
            'pay_date'   => $this->toLocalDateTime($this->pay_date),

            'created_by' => $this->createdBy?->name,
            'updated_by' => $this->updatedBy?->name,

            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),
        ];
    }
}
