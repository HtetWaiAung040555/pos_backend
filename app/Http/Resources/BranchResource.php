<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'location' => $this->location,

            'warehouse' => [
                'id' => $this->warehouse->id ?? null,
                'name' => $this->warehouse->name ?? null,
            ],

            'status' => [
                'id' => $this->status->id ?? null,
                'name' => $this->status->name ?? null,
            ],

            'created_by' => [
                'id' => $this->createdBy->id ?? null,
                'name' => $this->createdBy->name ?? null,
            ],
            
            'updated_by' => [
                'id' => $this->updatedBy->id ?? null,
                'name' => $this->updatedBy->name ?? null,
            ],

            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
            
        ];
    }
}
