<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use App\Models\Branch;
use App\Models\Counter;
use App\Models\Status;
use App\Models\User;

class UserFactory extends Factory
{
    protected $model = \App\Models\User::class;

    public function definition()
    {
        // User::first()?->id ?? User::factory()->create([
        //     'name' => 'Admin',
        //     'email' => 'admin@gmail.com',
        //     'password' => Hash::make('password123'),
        // ]);

        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('password'),
            'branch_id' => Branch::inRandomOrder()->first()?->id ?? 1,
            'counter_id' => Counter::inRandomOrder()->first()?->id ?? 1,
            'status_id' => Status::inRandomOrder()->first()?->id ?? 1,
            'created_by' => 1,
            'updated_by' => 1,
        ];
    }

}
