<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_foc_allocations', function (Blueprint $table) {
            $table->id();

            $table->foreignId('promotion_id')
                ->constrained('promotions')
                ->cascadeOnDelete();

            $table->foreignId('product_id')
                ->constrained('products');

            $table->integer('allocated_qty')->default(0);
            $table->integer('used_qty')->default(0);

            $table->foreignId('allocated_warehouse_id')
                ->nullable()
                ->constrained('warehouses')
                ->nullOnDelete();

            $table->timestamps();

            $table->unique(
                ['promotion_id', 'product_id', 'allocated_warehouse_id'],
                'promotion_foc_allocations_unique'
            );
        });

        // DB::statement('
        //     ALTER TABLE promotion_foc_allocations
        //     ADD CONSTRAINT chk_foc_usage
        //     CHECK (used_qty <= allocated_qty)
        // ');
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_foc_allocations');
    }

};
