<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_unit_price_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_unit_id')->constrained('product_units')->cascadeOnDelete();
            $table->decimal('min_qty', 18, 6)->default(0);
            $table->decimal('max_qty', 18, 6)->nullable();
            $table->decimal('price', 11, 2);
            $table->decimal('old_price', 11, 2)->nullable();
            $table->foreignId('status_id')->constrained('statuses')->restrictOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();

            $table->index(['product_unit_id', 'min_qty', 'max_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_unit_price_ranges');
    }
};
