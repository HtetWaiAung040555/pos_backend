<?php

namespace Database\Seeders;

use App\Models\Status;
use Illuminate\Database\Seeder;

class StatusSeeder extends Seeder
{
    public function run(): void
    {
        $statuses = ['Active', 'Inactive', 'Disabled', 'Pending', 'Hold', 'Unpaid', 'Complete', 'Void', 'Applied', 'Ended'];

        foreach ($statuses as $status) {
            Status::firstOrCreate(['name' => $status]);
        }
    }
}

// php artisan db:seed --class=StatusSeeder
