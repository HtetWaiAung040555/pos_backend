<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->foreignId('status_id')->after('payment_id')->nullable()->constrained('statuses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('customer_transactions', function (Blueprint $table) {
            $table->dropForeign(['status_id']);
            $table->dropColumn('status_id');
        });
    }
};

// php artisan migrate --path=database/migrations/2025_11_25_115203_add_status_to_customer_transactions_table.php