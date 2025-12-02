<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\Status;
use Illuminate\Database\Eloquent\Factories\Factory;

class CounterFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Counter ' . $this->faker->unique()->numberBetween(1, 100),
            'branch_id' => Branch::inRandomOrder()->first()?->id ?? 1,
            'status_id' => Status::inRandomOrder()->first()?->id ?? 1,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }
}
