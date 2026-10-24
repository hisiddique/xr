<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_debit_note_items', function (Blueprint $table) {
            $table->decimal('net_override', 12, 2)->nullable()->after('vat_amount');
            $table->decimal('vat_override', 12, 2)->nullable()->after('net_override');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_debit_note_items', function (Blueprint $table) {
            $table->dropColumn(['net_override', 'vat_override']);
        });
    }
};
