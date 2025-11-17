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
        Schema::create('customer_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('customer_hash')->unique();
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('successful_deliveries')->default(0);
            $table->unsignedInteger('failed_deliveries')->default(0);
            $table->unsignedInteger('late_deliveries')->default(0);
            $table->unsignedInteger('returns')->default(0);
            $table->unsignedInteger('cod_orders')->default(0);
            $table->unsignedInteger('cod_refusals')->default(0);
            $table->timestamp('first_order_at')->nullable();
            $table->timestamp('last_order_at')->nullable();
            // 0–100 %
            $table->decimal('delivery_success_rate', 5, 2)->nullable(); // π.χ. 87.50
            // 0–100 risk score (όσο μεγαλύτερο τόσο πιο risky)
            $table->unsignedTinyInteger('delivery_risk_score')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_stats');
    }
};
