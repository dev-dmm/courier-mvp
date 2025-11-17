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
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            // Παγκόσμιο κλειδί: email hash για cross-shop ταύτιση
            $table->string('customer_hash')->unique();
            // Optional "τελευταία γνωστά" στοιχεία (για internal χρήση)
            $table->string('primary_email')->nullable();
            $table->string('primary_name')->nullable();
            $table->string('primary_phone')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable(); // extra info, π.χ. last known address
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
