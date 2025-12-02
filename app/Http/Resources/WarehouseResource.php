<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WarehouseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

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
