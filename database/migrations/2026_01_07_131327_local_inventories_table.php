<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('local_inventories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('price', 11, 2);
            $table->integer('qty')->default(0);
            $table->string('image')->nullable();
            $table->string('barcode')->nullable()->unique();
            $table->unsignedBigInteger('created_by');
            $table->unsignedBigInteger('updated_by');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
