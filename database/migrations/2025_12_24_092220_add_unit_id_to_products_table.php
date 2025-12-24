<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->after('name')->constrained('units')->restrictOnDelete();
            
            $table->dropColumn('unit');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('unit')->after('name');

            $table->dropForeign(['unit_id']);
            $table->dropColumn('unit_id');
        });
    }
};
