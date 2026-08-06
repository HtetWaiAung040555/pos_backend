<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_topup', function (Blueprint $table) {
            $table->enum('type', ['deposit', 'withdraw'])->default('deposit')->after('customer_id');
        });

        DB::table('wallet_topup')
            ->whereNull('type')
            ->update(['type' => 'deposit']);
    }

    public function down(): void
    {
        Schema::table('wallet_topup', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};