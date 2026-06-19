<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('default_product_unit_id')
                ->nullable()
                ->after('barcode')
                ->constrained('product_units')
                ->nullOnDelete();

            $table->boolean('uom_enabled')
                ->default(false)
                ->after('default_product_unit_id');
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $this->addUomSnapshotFields($table, 'quantity');
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $this->addUomSnapshotFields($table, 'quantity');
        });

        Schema::table('sale_return_details', function (Blueprint $table) {
            $this->addUomSnapshotFields($table, 'quantity');
        });

        Schema::table('purchase_return_details', function (Blueprint $table) {
            $this->addUomSnapshotFields($table, 'quantity');
        });

        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->foreignId('product_unit_id')
                ->nullable()
                ->after('inventory_id')
                ->constrained('product_units')
                ->nullOnDelete();

            $table->foreignId('unit_id')
                ->nullable()
                ->after('product_unit_id')
                ->constrained('units')
                ->nullOnDelete();

            $table->decimal('unit_quantity', 18, 6)
                ->nullable()
                ->after('quantity_change');

            $table->decimal('base_quantity', 18, 6)
                ->nullable()
                ->after('unit_quantity');

            $table->decimal('conversion_to_base', 18, 6)
                ->nullable()
                ->after('base_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->dropForeign(['product_unit_id']);
            $table->dropForeign(['unit_id']);
            $table->dropColumn([
                'product_unit_id',
                'unit_id',
                'unit_quantity',
                'base_quantity',
                'conversion_to_base',
            ]);
        });

        Schema::table('purchase_return_details', function (Blueprint $table) {
            $this->dropUomSnapshotFields($table);
        });

        Schema::table('sale_return_details', function (Blueprint $table) {
            $this->dropUomSnapshotFields($table);
        });

        Schema::table('purchase_details', function (Blueprint $table) {
            $this->dropUomSnapshotFields($table);
        });

        Schema::table('sale_details', function (Blueprint $table) {
            $this->dropUomSnapshotFields($table);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['default_product_unit_id']);
            $table->dropColumn(['default_product_unit_id', 'uom_enabled']);
        });
    }

    private function addUomSnapshotFields(Blueprint $table, string $afterColumn): void
    {
        $table->foreignId('product_unit_id')
            ->nullable()
            ->after($afterColumn)
            ->constrained('product_units')
            ->nullOnDelete();

        $table->foreignId('unit_id')
            ->nullable()
            ->after('product_unit_id')
            ->constrained('units')
            ->nullOnDelete();

        $table->string('unit_name')
            ->nullable()
            ->after('unit_id');

        $table->decimal('unit_quantity', 18, 6)
            ->nullable()
            ->after('unit_name');

        $table->decimal('base_quantity', 18, 6)
            ->nullable()
            ->after('unit_quantity');

        $table->decimal('conversion_to_base', 18, 6)
            ->nullable()
            ->after('base_quantity');

        $table->string('unit_barcode')
            ->nullable()
            ->after('conversion_to_base');

        $table->foreignId('price_range_id')
            ->nullable()
            ->after('unit_barcode')
            ->constrained('product_unit_price_ranges')
            ->nullOnDelete();
    }

    private function dropUomSnapshotFields(Blueprint $table): void
    {
        $table->dropForeign(['product_unit_id']);
        $table->dropForeign(['unit_id']);
        $table->dropForeign(['price_range_id']);
        $table->dropColumn([
            'product_unit_id',
            'unit_id',
            'unit_name',
            'unit_quantity',
            'base_quantity',
            'conversion_to_base',
            'unit_barcode',
            'price_range_id',
        ]);
    }
};
