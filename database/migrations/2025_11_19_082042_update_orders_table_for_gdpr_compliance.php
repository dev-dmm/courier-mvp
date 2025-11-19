<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * GDPR Compliance: Remove raw PII columns and add hashed PII columns
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Remove raw PII columns
            $table->dropColumn([
                'customer_name',
                'customer_email',
                'customer_phone',
                'shipping_address_line1',
                'shipping_address_line2',
            ]);
            
            // Add hashed PII columns
            $table->string('customer_name_hash', 64)->nullable()->after('customer_hash');
            $table->string('customer_phone_hash', 64)->nullable()->after('customer_name_hash');
            $table->string('shipping_address_line1_hash', 64)->nullable()->after('customer_phone_hash');
            $table->string('shipping_address_line2_hash', 64)->nullable()->after('shipping_address_line1_hash');
        });
        
        Schema::table('customers', function (Blueprint $table) {
            // Remove raw PII columns
            $table->dropColumn([
                'primary_email',
                'primary_name',
                'primary_phone',
            ]);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Remove hashed PII columns
            $table->dropColumn([
                'customer_name_hash',
                'customer_phone_hash',
                'shipping_address_line1_hash',
                'shipping_address_line2_hash',
            ]);
            
            // Restore raw PII columns
            $table->string('customer_name')->nullable()->after('customer_hash');
            $table->string('customer_email')->nullable()->after('customer_name');
            $table->string('customer_phone')->nullable()->after('customer_email');
            $table->string('shipping_address_line1')->nullable()->after('customer_phone');
            $table->string('shipping_address_line2')->nullable()->after('shipping_address_line1');
        });
        
        Schema::table('customers', function (Blueprint $table) {
            // Restore raw PII columns
            $table->string('primary_email')->nullable()->after('customer_hash');
            $table->string('primary_name')->nullable()->after('primary_email');
            $table->string('primary_phone')->nullable()->after('primary_name');
        });
    }
};
