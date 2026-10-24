<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_debit_notes', function (Blueprint $table) {
            $table->boolean('vat_inclusive')->default(false)->after('vat_rate');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_debit_notes', function (Blueprint $table) {
            $table->dropColumn('vat_inclusive');
        });
    }
};
