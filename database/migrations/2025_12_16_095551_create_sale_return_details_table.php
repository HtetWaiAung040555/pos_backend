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
        Schema::create('sale_return_details', function (Blueprint $table) {
            $table->id();
            $table->string('sale_return_id');
            $table->foreign('sale_return_id')->references('id')->on('sale_returns')->restrictOnDelete();
            $table->foreignId('sale_detail_id')->constrained('sale_details')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('price',11,2);
            $table->decimal('total',11,2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sale_return_details');
    }
};
