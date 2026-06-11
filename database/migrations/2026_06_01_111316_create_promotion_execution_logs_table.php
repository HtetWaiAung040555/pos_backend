<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_execution_logs', function (Blueprint $table) {
            $table->id();

            $table->string('sale_id');
            $table->foreign('sale_id')
                ->references('id')
                ->on('sales')
                ->cascadeOnDelete();

            $table->foreignId('promotion_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->json('rule_matched_json')->nullable();
            $table->json('result_json')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_execution_logs');
    }
};
