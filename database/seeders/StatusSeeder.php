<?php

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = ['Active', 'Inactive', 'Disabled', 'Pending', 'Hold', 'Unpaid', 'Complete', 'Void'];

        foreach ($statuses as $status) {
            Status::create(['name' => $status]);
        }
    }
}

// php artisan db:seed --class=StatusSeeder