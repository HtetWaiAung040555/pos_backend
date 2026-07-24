<?php

use App\Http\Controllers\Api\PriceChangesController;
use App\Http\Controllers\Api\PromotionsController;
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

Artisan::command('promotions:refresh-lifecycle', function () {
    $result = app(PromotionsController::class)->refreshPromotionLifecycleStatuses();

    $this->info('Promotion lifecycle refresh completed.');
    $this->line('Scheduled promotions: '.$result['scheduled_promotions']);
    $this->line('Applied promotions: '.$result['applied_promotions']);
    $this->line('Expired promotions: '.$result['expired_promotions']);
    $this->line('Checked at: '.$result['checked_at']);
})->purpose('Refresh promotion statuses and return unused FOC stock');

Schedule::command('pricechanges:check-sales-prices')
    ->everyFiveMinutes()
    ->withoutOverlapping();

Schedule::command('promotions:refresh-lifecycle')
    ->everyMinute()
    ->withoutOverlapping();
