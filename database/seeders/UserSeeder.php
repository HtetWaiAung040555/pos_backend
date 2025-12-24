<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run()
    {
        // Admin
        User::create([
            'name'       => 'Admin',
            'email'      => 'admin@gmail.com',
            'password'   => Hash::make('123456789'),
            'status_id'  => 1,
            'role_id'    => 1,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        // Dev Admin
        User::create([
            'name'       => 'Dev Admin',
            'email'      => 'dev_admin@gmail.com',
            'password'   => Hash::make('123456789'),
            'status_id'  => 1,
            'role_id'    => 1,
            'created_by' => 1,
            'updated_by' => 1,
        ]);
    }
}


// php artisan db:seed --class=UserSeeder