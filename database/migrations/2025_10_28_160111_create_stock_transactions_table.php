<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->string('reference_id')->nullable();
            $table->enum('reference_type', [
                    'sale',
                    'purchase',
                    'opening',
                    'opening_adjustment',
                    'adjustment',
                    'purchase_update',
                    'purchase_void',
                    'purchase_return',
                    'purchase_return_update',
                    'purchase_return_void',
                    'opening_void',
                    'sale_void',
                    'sale_return',
                    'sale_return_update',
                    'sale_return_void'
                ])->nullable();
            $table->string('reason')->nullable();
            $table->integer('quantity_change');
            $table->enum('type', ['in', 'out'])->comment('in = added to stock, out = removed from stock');
            $table->unsignedBigInteger('created_by');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
