<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->enum('promo_type', [
                'PRODUCT_DISCOUNT',
                'ORDER_DISCOUNT',
                'FOC'
            ])->default('PRODUCT_DISCOUNT')->after('discount_value');

            $table->enum('condition_type', [
                'NONE',
                'ITEM_QTY',
                'ITEM_AMOUNT',
                'ORDER_QTY',
                'ORDER_AMOUNT'
            ])->default('NONE')->after('promo_type');

            $table->enum('promo_mode', [
                'TIER',
                'MULTIPLIER',
            ])->default('TIER')->after('condition_type');

            $table->decimal('max_reward_value', 12, 2)->nullable()->after('promo_mode');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['promo  _type', 'condition_type', 'promo_mode']);
        });
    }
};
