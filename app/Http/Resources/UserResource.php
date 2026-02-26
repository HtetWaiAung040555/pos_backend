<?php

namespace App\Http\Resources;

use App\Http\Resources\Concerns\FormatsLocalDateTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    use FormatsLocalDateTime;

    public function toArray(Request $request): array
    {
       return [
            'id'         => $this->id,
            'name'       => $this->name,
            'email'      => $this->email,
            'password'   => $this->password,
            'branch' => [
                'id' => $this->branch->id ?? null,
                'name' => $this->branch->name ?? null,
                'warehouse_id' => $this->branch->warehouse_id ?? null,
                'location' => $this->branch->location ?? null,
                'phone' => $this->branch->phone ?? null,
            ],

            'counter' => [
                'id' => $this->counter->id ?? null,
                'name' => $this->counter->name ?? null,
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

            'created_at' => $this->toLocalDateTime($this->created_at),
            'updated_at' => $this->toLocalDateTime($this->updated_at),

            'role' => [
                'id' => $this->role->id ?? null,
                'name' => $this->role->name ?? null,
                'permissions' => $this->role->permissions->map(function ($permission) {
                    return [
                        'id' => $permission->id,
                        'name' => $permission->name,
                        'action' => $permission->action
                    ];
                }) ?? []
            ]
            
        ];
    }
}
