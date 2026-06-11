<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->decimal('override_price', 10, 2)->nullable()->after('max_reward_value');
            $table->string('branch_scope_type')->default('ALL')->after('override_price');
            $table->string('warehouse_scope_type')->default('ALL')->after('branch_scope_type');
        });
    }

    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['min_qty', 'max_qty', 'override_price', 'branch_scope_type', 'warehouse_scope_type']);
        });
    }
    
};
