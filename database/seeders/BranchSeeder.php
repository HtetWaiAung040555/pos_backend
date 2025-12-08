<?php

namespace Database\Seeders;

use App\Models\Branch;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class BranchSeeder extends Seeder
{
    public function run(): void
    {
        Branch::create([
            'name' => 'Main Branch',
            'phone' => '',
            'location' => '',
            'warehouse_id' => 1,
            'status_id' => 1,
            'created_by' => 1,
            'updated_by' => 1
        ]);
    }
}

// php artisan db:seed --class=BranchSeeder