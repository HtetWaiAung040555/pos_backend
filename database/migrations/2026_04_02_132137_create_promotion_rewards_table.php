<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_rewards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->enum('reward_type', ['DISCOUNT', 'FREE_PRODUCT']);
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->integer('tier')->default(1);
            $table->decimal('reward_value', 12, 2)->nullable();
            $table->integer('reward_qty')->nullable();
            $table->timestamps();
            $table->index('tier');
            $table->index('reward_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_rewards');
    }
};
