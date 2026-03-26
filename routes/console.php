<?php

use App\Http\Controllers\Api\PriceChangesController;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('pricechanges:check-sales-prices', function () {
    $result = app(PriceChangesController::class)->applyStartedSalesPriceChanges();

    $this->info('Sales price change check completed.');
    $this->line('Applied price changes: '.$result['applied_price_changes']);
    $this->line('Applied products: '.$result['applied_products']);
    $this->line('Checked at: '.$result['checked_at']);
})->purpose('Apply started sales price changes to products');

Schedule::command('pricechanges:check-sales-prices')
    ->everyFiveMinutes()
    ->withoutOverlapping();
