<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_changes_products', function (Blueprint $table) {
            $table->foreignId('branch_id')
                ->nullable()
                ->after('price_change_id')
                ->constrained('branches')
                ->nullOnDelete();

            $table->foreignId('branch_product_id')
                ->nullable()
                ->after('branch_id');

            $table->foreignId('product_unit_id')
                ->nullable()
                ->after('product_id')
                ->constrained('product_units')
                ->nullOnDelete();

            $table->foreignId('branch_product_unit_price_id')
                ->nullable()
                ->after('product_unit_id');

            $table->foreignId('product_unit_price_range_id')
                ->nullable()
                ->after('branch_product_unit_price_id');

            $table->foreignId('branch_product_unit_price_range_id')
                ->nullable()
                ->after('product_unit_price_range_id');

            $table->foreignId('unit_id')
                ->nullable()
                ->after('branch_product_unit_price_range_id')
                ->constrained('units')
                ->nullOnDelete();

            $table->string('unit_name')
                ->nullable()
                ->after('unit_id');

            $table->decimal('conversion_to_base', 18, 6)
                ->nullable()
                ->after('unit_name');

            $table->decimal('min_qty', 18, 6)
                ->nullable()
                ->after('conversion_to_base');

            $table->decimal('max_qty', 18, 6)
                ->nullable()
                ->after('min_qty');

            $table->index(
                ['branch_id', 'product_id', 'product_unit_id'],
                'price_change_product_lookup_index'
            );

            $table->index(
                [
                    'price_change_id',
                    'branch_id',
                    'product_id',
                    'product_unit_id',
                    'min_qty',
                    'max_qty',
                ],
                'price_change_product_range_lookup'
            );

            $table->foreign('branch_product_id', 'pcp_branch_product_fk')
                ->references('id')
                ->on('branch_products')
                ->nullOnDelete();

            $table->foreign('branch_product_unit_price_id', 'pcp_branch_unit_price_fk')
                ->references('id')
                ->on('branch_product_unit_prices')
                ->nullOnDelete();

            $table->foreign('product_unit_price_range_id', 'pcp_unit_range_fk')
                ->references('id')
                ->on('product_unit_price_ranges')
                ->nullOnDelete();

            $table->foreign('branch_product_unit_price_range_id', 'pcp_branch_unit_range_fk')
                ->references('id')
                ->on('branch_product_unit_price_ranges')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('price_changes_products', function (Blueprint $table) {
            $table->dropIndex('price_change_product_range_lookup');
            $table->dropIndex('price_change_product_lookup_index');

            $table->dropForeign(['branch_id']);
            $table->dropForeign('pcp_branch_product_fk');
            $table->dropForeign(['product_unit_id']);
            $table->dropForeign('pcp_branch_unit_price_fk');
            $table->dropForeign('pcp_unit_range_fk');
            $table->dropForeign('pcp_branch_unit_range_fk');
            $table->dropForeign(['unit_id']);

            $table->dropColumn([
                'branch_id',
                'branch_product_id',
                'product_unit_id',
                'branch_product_unit_price_id',
                'product_unit_price_range_id',
                'branch_product_unit_price_range_id',
                'unit_id',
                'unit_name',
                'conversion_to_base',
                'min_qty',
                'max_qty',
            ]);
        });
    }
};
