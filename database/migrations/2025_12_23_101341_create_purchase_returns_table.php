<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_returns', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('purchase_id');
            $table->foreign('purchase_id')->references('id')->on('purchases')->restrictOnDelete();
            $table->string('supplier_id');
            $table->foreign('supplier_id')->references('id')->on('suppliers')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->decimal('total_amount',11,2);
            $table->text('remark')->nullable();
            $table->dateTime('return_date');
            $table->foreignId('payment_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('status_id')->constrained('statuses')->restrictOnDelete();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->unsignedBigInteger('void_by')->nullable();
            $table->foreign('void_by')->references('id')->on('users')->nullOnDelete();
            $table->timestamp('void_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_returns');
    }
};
