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
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('customer_category_id')->nullable()->after('credit_limit_id')->constrained('lookup_customer_categories')->nullOnDelete();
            $table->foreignId('revenue_type_id')->nullable()->after('customer_category_id')->constrained('lookup_revenue_types')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revenue_type_id');
            $table->dropConstrainedForeignId('customer_category_id');
        });
    }
};
