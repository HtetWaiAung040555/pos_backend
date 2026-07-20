<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'promotion_foc_allocations';

    private const UNIQUE_INDEX = 'promotion_foc_allocations_unique';

    private const WAREHOUSE_PRODUCT_INDEX = 'pfa_warehouse_product_index';

    private const BRANCH_PRODUCT_UNIT_INDEX = 'pfa_branch_product_unit_index';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('promotion_id')
                ->constrained('branches')
                ->restrictOnDelete();

            $table->decimal('allocated_base_qty', 18, 6)
                ->nullable()
                ->after('allocated_qty');

            $table->decimal('used_base_qty', 18, 6)
                ->nullable()
                ->after('used_qty');
        });

        // allocated_qty and used_qty currently represent the inventory/base-unit
        // quantities moved between inventories.qty and inventories.foc_qty.
        DB::table(self::TABLE)
            ->whereNull('allocated_base_qty')
            ->update(['allocated_base_qty' => DB::raw('allocated_qty')]);

        DB::table(self::TABLE)
            ->whereNull('used_base_qty')
            ->update(['used_base_qty' => DB::raw('used_qty')]);

        $this->backfillUnambiguousBranches();
        $this->replaceUniqueIndex();

        Schema::table(self::TABLE, function (Blueprint $table) {
            if (! Schema::hasIndex(self::TABLE, self::WAREHOUSE_PRODUCT_INDEX)) {
                $table->index(
                    ['allocated_warehouse_id', 'product_id'],
                    self::WAREHOUSE_PRODUCT_INDEX
                );
            }

            if (! Schema::hasIndex(self::TABLE, self::BRANCH_PRODUCT_UNIT_INDEX)) {
                $table->index(
                    ['branch_id', 'product_id', 'product_unit_id'],
                    self::BRANCH_PRODUCT_UNIT_INDEX
                );
            }
        });
    }

    public function down(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            if (Schema::hasIndex(self::TABLE, self::WAREHOUSE_PRODUCT_INDEX)) {
                $table->dropIndex(self::WAREHOUSE_PRODUCT_INDEX);
            }

            if (Schema::hasIndex(self::TABLE, self::BRANCH_PRODUCT_UNIT_INDEX)) {
                $table->dropIndex(self::BRANCH_PRODUCT_UNIT_INDEX);
            }

            if (Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
                $table->dropUnique(self::UNIQUE_INDEX);
            }

            $table->unique(
                ['promotion_id', 'product_id', 'product_unit_id', 'allocated_warehouse_id'],
                self::UNIQUE_INDEX
            );
        });

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
            $table->dropColumn([
                'branch_id',
                'allocated_base_qty',
                'used_base_qty',
            ]);
        });
    }

    private function backfillUnambiguousBranches(): void
    {
        DB::table(self::TABLE)
            ->select(['id', 'promotion_id', 'allocated_warehouse_id'])
            ->whereNull('branch_id')
            ->whereNotNull('allocated_warehouse_id')
            ->orderBy('id')
            ->chunkById(500, function ($allocations) {
                foreach ($allocations as $allocation) {
                    $branchIds = DB::table('promotion_branches')
                        ->join('branches', 'branches.id', '=', 'promotion_branches.branch_id')
                        ->where('promotion_branches.promotion_id', $allocation->promotion_id)
                        ->where('branches.warehouse_id', $allocation->allocated_warehouse_id)
                        ->distinct()
                        ->limit(2)
                        ->pluck('branches.id');

                    if ($branchIds->count() !== 1) {
                        continue;
                    }

                    DB::table(self::TABLE)
                        ->where('id', $allocation->id)
                        ->whereNull('branch_id')
                        ->update(['branch_id' => (int) $branchIds->first()]);
                }
            });
    }

    private function replaceUniqueIndex(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            if (Schema::hasIndex(self::TABLE, self::UNIQUE_INDEX)) {
                $table->dropUnique(self::UNIQUE_INDEX);
            }

            $table->unique(
                ['promotion_id', 'branch_id', 'product_id', 'product_unit_id'],
                self::UNIQUE_INDEX
            );
        });
    }
};
