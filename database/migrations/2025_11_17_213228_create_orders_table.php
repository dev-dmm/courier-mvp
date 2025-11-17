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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            // για γρήγορα queries χωρίς join
            $table->string('customer_hash')->index();
            // ID όπως το ξέρει το WooCommerce
            $table->string('external_order_id')->index();
            // Βασικά στοιχεία πελάτη (snapshot τη στιγμή της παραγγελίας)
            $table->string('customer_name')->nullable();
            $table->string('customer_email')->nullable();
            $table->string('customer_phone')->nullable();
            // Διευθύνσεις
            $table->string('shipping_address_line1')->nullable();
            $table->string('shipping_address_line2')->nullable();
            $table->string('shipping_city')->nullable();
            $table->string('shipping_postcode')->nullable();
            $table->string('shipping_country')->nullable();
            // Ποσά
            $table->decimal('total_amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EUR');
            // Woo status / methods
            $table->string('status')->default('pending')->index();  // π.χ. wc-processing
            $table->string('payment_method')->nullable();
            $table->string('payment_method_title')->nullable();
            $table->string('shipping_method')->nullable();
            $table->unsignedInteger('items_count')->nullable();
            // useful dates
            $table->timestamp('ordered_at')->nullable();      // Woo created date
            $table->timestamp('completed_at')->nullable();    // Woo completed date
            // extra raw / payload
            $table->json('meta')->nullable(); // items, raw Woo data, plugins info
            $table->timestamps();
            $table->unique(['shop_id', 'external_order_id']);
            $table->index(['shop_id', 'customer_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
