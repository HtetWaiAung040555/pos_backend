<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE customer_transactions MODIFY type ENUM('sale','payment','refund','adjustment','top-up','sale_void','withdraw') NULL");
    }

    public function down(): void
    {
        DB::table('customer_transactions')
            ->where('type', 'withdraw')
            ->update(['type' => 'adjustment']);

        DB::statement("ALTER TABLE customer_transactions MODIFY type ENUM('sale','payment','refund','adjustment','top-up','sale_void') NULL");
    }
};
