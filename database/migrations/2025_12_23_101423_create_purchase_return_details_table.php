<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_return_details', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_return_id');
            $table->foreign('purchase_return_id')->references('id')->on('purchase_returns')->restrictOnDelete();
            $table->foreignId('purchase_detail_id')->constrained('purchase_details')->restrictOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->restrictOnDelete();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->integer('quantity');
            $table->decimal('price',11,2);
            $table->decimal('total',11,2);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_return_details');
    }
};
