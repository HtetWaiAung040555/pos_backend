<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('customer_id');
            $table->foreign('customer_id')->references('id')->on('customers')->restrictOnDelete();
            $table->decimal('total_amount',11,2);
            $table->decimal('paid_amount',11,2);
            $table->decimal('due_amount',11,2);
            $table->foreignId('payment_id')->constrained('payment_methods')->restrictOnDelete();
            $table->foreignId('status_id')->constrained('statuses')->restrictOnDelete();
            $table->text('remark')->nullable();
            $table->dateTime('sale_date');
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
        Schema::dropIfExists('sales');
    }
};
