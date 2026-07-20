<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions_products', function (Blueprint $table) {
            $table->decimal('max_qty_per_sales_order', 18, 6)
                ->nullable()
                ->after('unit_id');
        });
    }

    public function down(): void
    {
        Schema::table('promotions_products', function (Blueprint $table) {
            $table->dropColumn('max_qty_per_sales_order');
        });
    }
};
