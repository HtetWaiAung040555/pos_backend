<?php

namespace Database\Seeders;

use App\Models\Counter;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class CounterSeeder extends Seeder
{
    public function run(): void
    {
        Counter::create([
            'name' => 'Counter 1',
            'branch_id' => 1,
            'status_id' => 1,
            'created_by' => 1,
            'updated_by' => 1
        ]);
    }
}

// php artisan db:seed --class=CounterSeeder