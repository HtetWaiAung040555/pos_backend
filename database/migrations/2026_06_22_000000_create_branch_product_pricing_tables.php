<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('price', 11, 2)->nullable();
            $table->decimal('old_price', 11, 2)->nullable();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'product_id'], 'branch_products_branch_product_unique');
            $table->index('product_id');
        });

        Schema::create('branch_product_unit_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_product_id')->constrained('branch_products')->cascadeOnDelete();
            $table->foreignId('product_unit_id')->constrained('product_units')->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->string('unit_name')->nullable();
            $table->decimal('conversion_to_base', 18, 6)->nullable();
            $table->decimal('price', 11, 2);
            $table->decimal('old_price', 11, 2)->nullable();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(
                ['branch_product_id', 'product_unit_id'],
                'branch_product_unit_prices_unique'
            );
            $table->index('product_unit_id');
        });

        Schema::create('branch_product_unit_price_ranges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('branch_product_unit_price_id');
            $table->decimal('min_qty', 18, 6)->default(0);
            $table->decimal('max_qty', 18, 6)->nullable();
            $table->decimal('price', 11, 2);
            $table->decimal('old_price', 11, 2)->nullable();
            $table->foreignId('status_id')->nullable()->constrained('statuses')->nullOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(
                ['branch_product_unit_price_id', 'min_qty', 'max_qty'],
                'branch_product_unit_price_ranges_lookup'
            );

            $table->foreign(
                'branch_product_unit_price_id',
                'bpup_ranges_price_fk'
            )
                ->references('id')
                ->on('branch_product_unit_prices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_product_unit_price_ranges');
        Schema::dropIfExists('branch_product_unit_prices');
        Schema::dropIfExists('branch_products');
    }
};
