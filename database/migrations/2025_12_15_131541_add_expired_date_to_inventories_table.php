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
        Schema::table('inventories', function (Blueprint $table) {
            $table->date('expired_date')->nullable()->after('qty');
            $table->dateTime('void_at')->nullable()->after('warehouse_id');
            $table->unsignedBigInteger('void_by')->nullable()->after('void_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropColumn('expired_date');
            $table->dropColumn('void_at');
            $table->dropColumn('void_by');
        });
    }
};
