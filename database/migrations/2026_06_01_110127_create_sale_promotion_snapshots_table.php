<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sale_promotion_snapshots')) {
            if (!$this->columnIsVarchar('sale_promotion_snapshots', 'sale_id')) {
                DB::statement('ALTER TABLE sale_promotion_snapshots MODIFY sale_id VARCHAR(255) NOT NULL');
            }

            Schema::table('sale_promotion_snapshots', function (Blueprint $table) {
                if (!$this->foreignKeyExists('sale_promotion_snapshots', 'sale_promotion_snapshots_sale_id_foreign')) {
                    $table->foreign('sale_id')
                        ->references('id')
                        ->on('sales')
                        ->cascadeOnDelete();
                }

                if (!$this->foreignKeyExists('sale_promotion_snapshots', 'sale_promotion_snapshots_promotion_id_foreign')) {
                    $table->foreign('promotion_id')
                        ->references('id')
                        ->on('promotions')
                        ->cascadeOnDelete();
                }

                if (!$this->indexExists('sale_promotion_snapshots', 'sale_promotion_snapshots_promo_type_index')) {
                    $table->index('promo_type');
                }
            });

            return;
        }

        Schema::create('sale_promotion_snapshots', function (Blueprint $table) {
            $table->id();

            $table->string('sale_id');
            $table->foreign('sale_id')
                ->references('id')
                ->on('sales')
                ->cascadeOnDelete();

            $table->foreignId('promotion_id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('promo_type');
            $table->string('condition_type')->nullable();
            $table->string('promo_mode')->nullable();

            // immutable execution snapshot
            $table->json('snapshot_json')->nullable();

            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('final_amount', 10, 2)->default(0);

            $table->timestamps();

            $table->index('promo_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_promotion_snapshots');
    }

    private function foreignKeyExists(string $table, string $constraint): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.table_constraints')
            ->where('constraint_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('constraint_name', $constraint)
            ->where('constraint_type', 'FOREIGN KEY')
            ->exists();
    }

    private function indexExists(string $table, string $index): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }

        return DB::table('information_schema.statistics')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('index_name', $index)
            ->exists();
    }

    private function columnIsVarchar(string $table, string $column): bool
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return true;
        }

        return DB::table('information_schema.columns')
            ->where('table_schema', DB::getDatabaseName())
            ->where('table_name', $table)
            ->where('column_name', $column)
            ->where('data_type', 'varchar')
            ->exists();
    }
};
