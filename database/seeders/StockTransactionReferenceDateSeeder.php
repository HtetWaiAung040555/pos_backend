<?php

namespace Database\Seeders;

use App\Models\StockTransaction;
use Illuminate\Database\Seeder;

class StockTransactionReferenceDateSeeder extends Seeder
{
    public function run(): void
    {
        $transactions = StockTransaction::all();

        foreach ($transactions as $tx) {
            switch ($tx->reference_type) {
                case 'opening':
                    $tx->reference_id = $tx->inventory?->id;
                    $tx->reference_date = $tx->inventory?->created_at;
                    break;

                case 'sale':
                case 'sale_update':
                case 'sale_void':
                    $tx->reference_date = $tx->sale?->sale_date;
                    break;

                case 'sale_return':
                case 'sale_return_update':
                case 'sale_return_void':
                    $tx->reference_date = $tx->saleReturn?->sale_return_date;
                    break;

                case 'purchase':
                case 'purchase_update':
                case 'purchase_void':
                    $tx->reference_date = $tx->purchase?->purchase_date;
                    break;

                case 'purchase_return':
                case 'purchase_return_update':
                case 'purchase_return_void':
                    $tx->reference_date = $tx->purchaseReturn?->purchase_date;
                    break;
                
                default:
                    $tx->reference_date = null;
            }

            $tx->save();
        }

        $this->command->info('Reference dates updated for all stock transactions!');
    }
}

// php artisan make:seeder StockTransactionReferenceDateSeeder
// php artisan db:seed --class=StockTransactionReferenceDateSeeder