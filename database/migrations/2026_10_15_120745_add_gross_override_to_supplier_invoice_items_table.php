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
        Schema::table('supplier_invoice_items', function (Blueprint $table) {
            $table->decimal('gross_override', 12, 2)->nullable()->after('line_total');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('supplier_invoice_items', function (Blueprint $table) {
            $table->dropColumn('gross_override');
        });
    }
};
