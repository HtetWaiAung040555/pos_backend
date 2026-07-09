<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('products')
            ->select([
                'id',
                'unit_id',
                'barcode',
                'price',
                'old_price',
                'purchase_price',
                'old_purchase_price',
                'status_id',
                'created_by',
                'updated_by',
                'default_product_unit_id',
                'uom_enabled',
            ])
            ->whereNotNull('unit_id')
            ->chunkById(200, function ($products) use ($now) {
                foreach ($products as $product) {
                    $defaultProductUnitId = $product->default_product_unit_id;

                    if (!$defaultProductUnitId) {
                        $defaultProductUnitId = DB::table('product_units')
                            ->where('product_id', $product->id)
                            ->where('unit_id', $product->unit_id)
                            ->value('id');
                    }

                    if (!$defaultProductUnitId) {
                        $defaultProductUnitId = DB::table('product_units')->insertGetId([
                            'product_id' => $product->id,
                            'unit_id' => $product->unit_id,
                            'barcode' => $this->availableProductUnitBarcode($product->barcode),
                            'conversion_to_base' => 1,
                            'price' => $product->price ?? 0,
                            'old_price' => $product->old_price ?? $product->price ?? 0,
                            'purchase_price' => $product->purchase_price ?? 0,
                            'old_purchase_price' => $product->old_purchase_price ?? $product->purchase_price ?? 0,
                            'is_base_unit' => true,
                            'is_default_sale_unit' => true,
                            'sort_order' => 0,
                            'status_id' => $product->status_id,
                            'created_by' => $product->created_by,
                            'updated_by' => $product->updated_by,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'default_product_unit_id' => $defaultProductUnitId,
                            'uom_enabled' => $product->uom_enabled ?? false,
                            'updated_at' => $now,
                        ]);
                }
            });
    }

    public function down(): void
    {
        // Data backfill is intentionally not reversed to avoid deleting product units
        // that may have been used by sales, purchases, promotions, or branch prices.
    }

    private function availableProductUnitBarcode(?string $barcode): ?string
    {
        if (!$barcode) {
            return null;
        }

        return DB::table('product_units')
            ->where('barcode', $barcode)
            ->exists()
                ? null
                : $barcode;
    }
};
