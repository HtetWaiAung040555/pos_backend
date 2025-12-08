<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dateTime('void_at')->nullable()->after('status_id');
            $table->unsignedBigInteger('void_by')->nullable()->after('void_at');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['void_at', 'void_by']);
        });
    }

};

// php artisan migrate --path=/database/migrations/2025_12_08_135702_add_void_fields_to_promotions_table.php
