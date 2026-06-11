<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_conditions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->integer('group_no')->default(1);
            $table->integer('tier')->default(1);
            $table->enum('condition_type', ['ITEM_QTY', 'ITEM_AMOUNT', 'ORDER_QTY', 'ORDER_AMOUNT']);
            $table->string('operator')->default('>=');
            $table->decimal('target_value', 12, 2)->nullable();
            $table->decimal('target_value_to', 10, 2)->nullable();
            $table->timestamps();
            $table->index('group_no');
            $table->index('tier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_conditions');
    }
};
