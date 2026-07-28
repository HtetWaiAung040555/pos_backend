<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->decimal('purchase_price', 15, 6)->default(0)->change();
            $table->decimal('old_purchase_price', 15, 6)->default(0)->change();
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->decimal('price', 15, 6)->change();
        });
    }

    public function down(): void
    {
        Schema::table('product_units', function (Blueprint $table) {
            $table->decimal('purchase_price', 11, 6)->default(0)->change();
            $table->decimal('old_purchase_price', 11, 6)->default(0)->change();
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $table->decimal('price', 11, 6)->change();
        });
    }
};
