<?php

namespace Database\Seeders;

use App\Models\PaymentMethod;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PaymentMethodSeeder extends Seeder
{
    public function run(): void
    {
        $payment_methods = [
            ['id' => 1, 'name' => 'Cash', 'is_default' => true, 'status_id' => 1, 'created_by' => 1, 'updated_by' => 1],
            ['id' => 2, 'name' => 'Credit', 'is_default' => true, 'status_id' => 1, 'created_by' => 1, 'updated_by' => 1],
            ['id' => 3, 'name' => 'Wallet', 'is_default' => true, 'status_id' => 1, 'created_by' => 1, 'updated_by' => 1],
            ['id' => 4, 'name' => 'Kpay', 'status_id' => 1, 'created_by' => 1, 'updated_by' => 1]
        ];

        foreach ($payment_methods as $payment_method) {
            PaymentMethod::updateOrCreate(
                ['id' => $payment_method['id']],
                $payment_method
            );
        }
    }
}
