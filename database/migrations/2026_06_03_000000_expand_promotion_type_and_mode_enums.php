<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE promotions
            MODIFY promo_type ENUM('PRODUCT_DISCOUNT', 'ORDER_DISCOUNT', 'FOC', 'PRICE_OVERRIDE')
            NOT NULL DEFAULT 'PRODUCT_DISCOUNT'
        ");

        DB::statement("
            ALTER TABLE promotions
            MODIFY promo_mode ENUM('NORMAL', 'TIER', 'MULTIPLIER', 'MIX_MATCH')
            NOT NULL DEFAULT 'NORMAL'
        ");
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE promotions
            MODIFY promo_type ENUM('PRODUCT_DISCOUNT', 'ORDER_DISCOUNT', 'FOC')
            NOT NULL DEFAULT 'PRODUCT_DISCOUNT'
        ");

        DB::statement("
            ALTER TABLE promotions
            MODIFY promo_mode ENUM('TIER', 'MULTIPLIER')
            NOT NULL DEFAULT 'TIER'
        ");
    }
};
