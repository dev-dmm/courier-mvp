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
        Schema::create('courier_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('voucher_id')
                ->constrained()
                ->cascadeOnDelete();
            $table->string('courier_name')->nullable();      // redundancy
            $table->string('event_code')->nullable();        // π.χ. DELIVERED, FAILED, OUT_FOR_DELIVERY
            $table->string('event_description')->nullable();
            $table->string('location')->nullable();          // πόλη/κέντρο διανομής
            $table->timestamp('event_time')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();
            $table->index(['voucher_id', 'event_time']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courier_events');
    }
};
