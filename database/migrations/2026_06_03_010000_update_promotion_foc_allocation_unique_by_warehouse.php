<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if (
            $this->indexColumns('promotion_foc_allocations', 'promotion_foc_allocations_unique') ===
            ['promotion_id', 'product_id', 'allocated_warehouse_id']
        ) {
            return;
        }

        if (!$this->indexExists('promotion_foc_allocations', 'promotion_foc_allocations_product_id_foreign')) {
            Schema::table('promotion_foc_allocations', function ($table) {
                $table->index('product_id', 'promotion_foc_allocations_product_id_foreign');
            });
        }

        if (!$this->indexExists('promotion_foc_allocations', 'promotion_foc_allocations_allocated_warehouse_id_foreign')) {
            Schema::table('promotion_foc_allocations', function ($table) {
                $table->index('allocated_warehouse_id', 'promotion_foc_allocations_allocated_warehouse_id_foreign');
            });
        }

        if ($this->indexExists('promotion_foc_allocations', 'promotion_foc_allocations_unique')) {
            Schema::table('promotion_foc_allocations', function ($table) {
                $table->dropUnique('promotion_foc_allocations_unique');
            });
        }

        Schema::table('promotion_foc_allocations', function ($table) {
            $table->unique(
                ['promotion_id', 'product_id', 'allocated_warehouse_id'],
                'promotion_foc_allocations_unique'
            );
        });
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return;
        }

        if ($this->indexExists('promotion_foc_allocations', 'promotion_foc_allocations_unique')) {
            Schema::table('promotion_foc_allocations', function ($table) {
                $table->dropUnique('promotion_foc_allocations_unique');
            });
        }

        Schema::table('promotion_foc_allocations', function ($table) {
            $table->unique(
                ['promotion_id', 'product_id'],
                'promotion_foc_allocations_unique'
            );
        });
    }

    private function indexExists(string $table, string $index): bool
    {
        $database = DB::getDatabaseName();

        return (int) DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->count() > 0;
    }

    private function indexColumns(string $table, string $index): array
    {
        $database = DB::getDatabaseName();

        return DB::table('information_schema.statistics')
            ->where('table_schema', $database)
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->orderBy('seq_in_index')
            ->pluck('column_name')
            ->all();
    }
};
