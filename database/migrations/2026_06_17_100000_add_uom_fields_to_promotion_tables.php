<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addPromotionConditionUomFields();
        $this->addPromotionRewardUomFields();
        $this->addPromotionProductUomFields();
        $this->addPromotionFocAllocationUomFields();
        $this->updateFocAllocationUniqueIndex();
    }

    public function down(): void
    {
        $this->restoreFocAllocationUniqueIndex();

        Schema::table('promotion_foc_allocations', function (Blueprint $table) {
            $table->dropForeign(['product_unit_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn([
                'product_unit_id',
                'unit_id',
                'unit_name',
                'unit_quantity',
                'base_quantity',
                'conversion_to_base',
            ]);
        });

        Schema::table('promotions_products', function (Blueprint $table) {
            $table->dropIndex('promotions_products_uom_index');
            $table->dropForeign(['product_unit_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn(['product_unit_id', 'unit_id']);
        });

        Schema::table('promotion_rewards', function (Blueprint $table) {
            $table->dropForeign(['product_unit_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn([
                'product_unit_id',
                'unit_id',
                'unit_name',
                'conversion_to_base',
                'override_price',
            ]);
        });

        Schema::table('promotion_conditions', function (Blueprint $table) {
            $table->dropForeign(['product_unit_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn([
                'product_unit_id',
                'unit_id',
                'unit_name',
                'conversion_to_base',
            ]);
        });
    }

    private function addPromotionConditionUomFields(): void
    {
        if (!Schema::hasColumn('promotion_conditions', 'product_unit_id')) {
            Schema::table('promotion_conditions', function (Blueprint $table) {
                $table->foreignId('product_unit_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_units')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('promotion_conditions', 'unit_id')) {
            Schema::table('promotion_conditions', function (Blueprint $table) {
                $table->foreignId('unit_id')
                    ->nullable()
                    ->after('product_unit_id')
                    ->constrained('units')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('promotion_conditions', 'unit_name')) {
            Schema::table('promotion_conditions', function (Blueprint $table) {
                $table->string('unit_name')->nullable()->after('unit_id');
            });
        }

        if (!Schema::hasColumn('promotion_conditions', 'conversion_to_base')) {
            Schema::table('promotion_conditions', function (Blueprint $table) {
                $table->decimal('conversion_to_base', 18, 6)->nullable()->after('unit_name');
            });
        }
    }

    private function addPromotionRewardUomFields(): void
    {
        if (!Schema::hasColumn('promotion_rewards', 'product_unit_id')) {
            Schema::table('promotion_rewards', function (Blueprint $table) {
                $table->foreignId('product_unit_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_units')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('promotion_rewards', 'unit_id')) {
            Schema::table('promotion_rewards', function (Blueprint $table) {
                $table->foreignId('unit_id')
                    ->nullable()
                    ->after('product_unit_id')
                    ->constrained('units')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('promotion_rewards', 'unit_name')) {
            Schema::table('promotion_rewards', function (Blueprint $table) {
                $table->string('unit_name')->nullable()->after('unit_id');
            });
        }

        if (!Schema::hasColumn('promotion_rewards', 'conversion_to_base')) {
            Schema::table('promotion_rewards', function (Blueprint $table) {
                $table->decimal('conversion_to_base', 18, 6)->nullable()->after('unit_name');
            });
        }

        if (!Schema::hasColumn('promotion_rewards', 'override_price')) {
            Schema::table('promotion_rewards', function (Blueprint $table) {
                $table->decimal('override_price', 11, 2)->nullable()->after('reward_value');
            });
        }
    }

    private function addPromotionProductUomFields(): void
    {
        if (!Schema::hasColumn('promotions_products', 'product_unit_id')) {
            Schema::table('promotions_products', function (Blueprint $table) {
                $table->foreignId('product_unit_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_units')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('promotions_products', 'unit_id')) {
            Schema::table('promotions_products', function (Blueprint $table) {
                $table->foreignId('unit_id')
                    ->nullable()
                    ->after('product_unit_id')
                    ->constrained('units')
                    ->nullOnDelete();
            });
        }

        if (!$this->indexExists('promotions_products', 'promotions_products_uom_index')) {
            Schema::table('promotions_products', function (Blueprint $table) {
                $table->index(
                    ['promotion_id', 'product_id', 'product_unit_id'],
                    'promotions_products_uom_index'
                );
            });
        }
    }

    private function addPromotionFocAllocationUomFields(): void
    {
        if (!Schema::hasColumn('promotion_foc_allocations', 'product_unit_id')) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                $table->foreignId('product_unit_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('product_units')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('promotion_foc_allocations', 'unit_id')) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                $table->foreignId('unit_id')
                    ->nullable()
                    ->after('product_unit_id')
                    ->constrained('units')
                    ->nullOnDelete();
            });
        }

        if (!Schema::hasColumn('promotion_foc_allocations', 'unit_name')) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                $table->string('unit_name')->nullable()->after('unit_id');
            });
        }

        if (!Schema::hasColumn('promotion_foc_allocations', 'unit_quantity')) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                $table->decimal('unit_quantity', 18, 6)->nullable()->after('unit_name');
            });
        }

        if (!Schema::hasColumn('promotion_foc_allocations', 'base_quantity')) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                $table->decimal('base_quantity', 18, 6)->nullable()->after('unit_quantity');
            });
        }

        if (!Schema::hasColumn('promotion_foc_allocations', 'conversion_to_base')) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                $table->decimal('conversion_to_base', 18, 6)->nullable()->after('base_quantity');
            });
        }
    }

    private function updateFocAllocationUniqueIndex(): void
    {
        $newColumns = ['promotion_id', 'product_id', 'product_unit_id', 'allocated_warehouse_id'];

        if ($this->indexColumns('promotion_foc_allocations', 'promotion_foc_allocations_unique') === $newColumns) {
            return;
        }

        // The old unique index may be the only index supporting promotion_id's FK.
        if (!$this->indexExists('promotion_foc_allocations', 'promotion_foc_allocations_promotion_id_index')) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                $table->index('promotion_id', 'promotion_foc_allocations_promotion_id_index');
            });
        }

        Schema::table('promotion_foc_allocations', function (Blueprint $table) {
            if ($this->indexExists('promotion_foc_allocations', 'promotion_foc_allocations_unique')) {
                $table->dropUnique('promotion_foc_allocations_unique');
            }

            $table->unique(
                ['promotion_id', 'product_id', 'product_unit_id', 'allocated_warehouse_id'],
                'promotion_foc_allocations_unique'
            );
        });
    }

    private function restoreFocAllocationUniqueIndex(): void
    {
        $oldColumns = ['promotion_id', 'product_id', 'allocated_warehouse_id'];

        if ($this->indexColumns('promotion_foc_allocations', 'promotion_foc_allocations_unique') !== $oldColumns) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                if ($this->indexExists('promotion_foc_allocations', 'promotion_foc_allocations_unique')) {
                    $table->dropUnique('promotion_foc_allocations_unique');
                }

                $table->unique(
                    ['promotion_id', 'product_id', 'allocated_warehouse_id'],
                    'promotion_foc_allocations_unique'
                );
            });
        }

        if ($this->indexExists('promotion_foc_allocations', 'promotion_foc_allocations_promotion_id_index')) {
            Schema::table('promotion_foc_allocations', function (Blueprint $table) {
                $table->dropIndex('promotion_foc_allocations_promotion_id_index');
            });
        }
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return Schema::hasIndex($table, $index);
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function indexColumns(string $table, string $index): array
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return [];
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->orderBy('seq_in_index')
            ->pluck('column_name')
            ->all();
    }
};
