<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')
                ->nullable()
                ->after('id')
                ->constrained('categories')
                ->restrictOnDelete();
            $table->string('code')->nullable()->unique()->after('name');
            $table->unsignedInteger('sort_order')->default(0)->after('code');
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn(['code', 'sort_order']);
        });
    }
};
