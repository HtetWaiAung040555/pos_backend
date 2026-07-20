<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_changes', function (Blueprint $table) {
            $table->dateTime('ended_at')->nullable()->after('end_at');
            $table->unsignedBigInteger('ended_by')->nullable()->after('ended_at');
            $table->text('end_reason')->nullable()->after('ended_by');

            $table->index(['type', 'status_id', 'end_at', 'ended_at'], 'price_changes_due_end_index');
        });

        DB::table('statuses')->insertOrIgnore([
            'name' => 'Ended',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::table('price_changes', function (Blueprint $table) {
            $table->dropIndex('price_changes_due_end_index');
            $table->dropColumn(['ended_at', 'ended_by', 'end_reason']);
        });
    }
};
