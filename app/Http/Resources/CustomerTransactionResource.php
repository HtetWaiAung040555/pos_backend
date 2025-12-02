<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'customer_id' => $this->customer_id,
            'sale_id'     => $this->sale_id,
            'type'        => $this->type,
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
            'pay_date'   => $this->pay_date,

            'created_by' => $this->createdBy?->name,
            'updated_by' => $this->updatedBy?->name,

            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
