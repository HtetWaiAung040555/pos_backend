<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Remove old decimal columns
            $table->dropColumn(['payable', 'paid_amount', 'total']);

            // Add new balance column
            $table->decimal('balance', 15, 2)->default(0.00)->after('payment_id');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            // Re-add original columns if rollback
            $table->decimal('payable', 15, 2)->default(0.00);
            $table->decimal('paid_amount', 15, 2)->default(0.00);
            $table->decimal('total', 15, 2)->default(0.00);

            // Remove balance column
            $table->dropColumn('balance');
        });
    }
};

// php artisan migrate --path=database/migrations/2025_11_25_100746_update_customers_table_add_balance.php