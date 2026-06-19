<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('unit_id')->constrained('units')->restrictOnDelete();
            $table->string('barcode')->nullable()->unique();
            $table->decimal('conversion_to_base', 18, 6)->default(1);
            $table->decimal('price', 11, 2)->default(0);
            $table->decimal('old_price', 11, 2)->default(0);
            $table->decimal('purchase_price', 11, 6)->default(0);
            $table->decimal('old_purchase_price', 11, 6)->default(0);
            $table->boolean('is_base_unit')->default(false);
            $table->boolean('is_default_sale_unit')->default(false);
            $table->integer('sort_order')->default(0);
            $table->foreignId('status_id')->constrained('statuses')->restrictOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
            $table->index('product_id');
            $table->index('unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
    }
};
