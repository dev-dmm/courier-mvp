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
        Schema::create('vouchers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('customer_hash')->index();
            $table->string('voucher_number')->index();
            $table->string('courier_name')->nullable();      // π.χ. BoxNow, ELTA
            $table->string('courier_service')->nullable();  // π.χ. locker, door-to-door
            $table->string('tracking_url')->nullable();
            // High-level status
            // created / shipped / in_transit / delivered / returned / failed
            $table->string('status')->default('created')->index();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('meta')->nullable(); // raw courier payload κλπ
            $table->timestamps();
            $table->unique(['shop_id', 'voucher_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vouchers');
    }
};
