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
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->decimal('trade_discount', 5, 2)->default(0)->after('status');
            $table->decimal('discount_amount', 12, 2)->default(0)->after('trade_discount');
            $table->boolean('discount_on_gross')->default(false)->after('discount_amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn(['trade_discount', 'discount_amount', 'discount_on_gross']);
        });
    }
};
