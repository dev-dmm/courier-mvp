<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('customer_stats', function (Blueprint $table) {
            // Remove legacy columns related to order status
            // Risk score is now calculated only from vouchers (returns, late deliveries)
            $table->dropColumn([
                'successful_deliveries',
                'failed_deliveries',
                'cod_orders',
                'cod_refusals',
                'delivery_success_rate',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_stats', function (Blueprint $table) {
            $table->unsignedInteger('successful_deliveries')->default(0)->after('total_orders');
            $table->unsignedInteger('failed_deliveries')->default(0)->after('successful_deliveries');
            $table->unsignedInteger('cod_orders')->default(0)->after('returns');
            $table->unsignedInteger('cod_refusals')->default(0)->after('cod_orders');
            $table->decimal('delivery_success_rate', 5, 2)->nullable()->after('last_order_at');
        });
    }
};
