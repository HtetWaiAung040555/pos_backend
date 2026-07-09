<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('id')
                ->constrained('branches')
                ->nullOnDelete();

            $table->index(['branch_id', 'warehouse_id'], 'sales_branch_warehouse_index');
        });

        DB::table('sales')
            ->join('branches', 'sales.warehouse_id', '=', 'branches.warehouse_id')
            ->whereNull('sales.branch_id')
            ->update(['sales.branch_id' => DB::raw('branches.id')]);
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropIndex('sales_branch_warehouse_index');
            $table->dropForeign(['branch_id']);
            $table->dropColumn('branch_id');
        });
    }
};
