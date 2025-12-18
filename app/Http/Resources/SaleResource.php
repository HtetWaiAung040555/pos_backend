<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // return [
        //     'id'            => $this->id,
        //     'customer'      => new CustomerResource($this->whenLoaded('customer')),
        //     'status'        => new StatusResource($this->whenLoaded('status')),
        //     'paymentMethod' => new PaymentMethodResource($this->whenLoaded('paymentMethod')),

        //     'total_amount'  => $this->total_amount,
        //     'paid_amount'   => $this->paid_amount,
        //     'due_amount'    => $this->due_amount,
        //     'remark'        => $this->remark,
        //     'sale_date'     => $this->sale_date,

        //     // Sale details + product info
        //     'details'       => SaleDetailResource::collection($this->whenLoaded('details')),

        //     // Created & Updated by
        //     'created_by'    => new UserResource($this->whenLoaded('createdBy')),
        //     'updated_by'    => new UserResource($this->whenLoaded('updatedBy')),

        //     // Void Info
        //     'void_at'       => $this->void_at,
        //     'void_by'       => new UserResource($this->whenLoaded('voidBy')), 

        //     'created_at'    => $this->created_at,
        //     'updated_at'    => $this->updated_at,
        // ];

        return [
            'id' => $this->id,
            'warehouse' => [
                'id' => $this->warehouse->id ?? null,
                'name' => $this->warehouse->name ?? null,
            ],
            'customer' => [
                'id' => $this->customer->id ?? null,
                'name' => $this->customer->name ?? null,
                'balance' => $this->customer->balance ?? 0,
            ],
            'payment_method' => [
                'id' => $this->paymentMethod->id ?? null,
                'name' => $this->paymentMethod->name ?? null,
            ],
            'status' => [
                'id' => $this->status->id ?? null,
                'name' => $this->status->name ?? null,
            ],
            'total_amount' => $this->total_amount,
            'paid_amount' => $this->paid_amount,
            'due_amount' => $this->due_amount,
            'remark' => $this->remark,
            'sale_date' => $this->sale_date,
            'warehouse_id' => $this->warehouse_id,
            'created_by' => $this->createdBy->name ?? null,
            'updated_by' => $this->updatedBy->name ?? null,
            'details' => SaleDetailResource::collection($this->whenLoaded('details')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
