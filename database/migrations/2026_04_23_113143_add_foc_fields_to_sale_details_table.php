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
        Schema::table('sale_details', function (Blueprint $table) {
            $table->boolean('is_foc')
                ->default(false)
                ->after('promotion_id');

            $table->unsignedBigInteger('reward_id')
                ->nullable()
                ->after('is_foc');

            $table->foreign('reward_id')
                ->references('id')
                ->on('promotion_rewards')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('sale_details', function (Blueprint $table) {
            $table->dropForeign(['reward_id']);
            $table->dropColumn(['is_foc', 'reward_id']);
        });
    }
};
