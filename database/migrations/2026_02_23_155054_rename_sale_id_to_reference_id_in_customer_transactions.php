<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->dropForeign('customer_transactions_sale_id_foreign');
            $table->renameColumn('sale_id', 'reference_id');
        });
    }

    public function down(): void
    {
        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->renameColumn('reference_id', 'sale_id');
            $table->foreign('sale_id')->references('id')->on('sales')->onDelete('cascade');
        });
    }
};
