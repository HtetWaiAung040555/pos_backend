<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['id' => 1, 'name' => 'Admin', 'desc' => 'Administrator with all permissions', 'status_id' => 1, 'created_by' => 1, 'updated_by' => 1],
            ['id' => 2, 'name' => 'Casher', 'desc' => 'Office Staff', 'status_id' => 1, 'created_by' => 1, 'updated_by' => 1],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['id' => $role['id']],
                $role
            );
        }
    }
}

// php artisan db:seed --class=RoleSeeder