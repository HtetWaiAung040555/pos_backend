<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('price_chages_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_change_id')->constrained('price_changes')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->enum('type', [
                'sale',
                'purchase'
            ])->nullable();
            $table->decimal('old_price', 15, 2);
            $table->decimal('new_price', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('price_chages_products');
    }
};
