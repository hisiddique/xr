<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable();
        });

        Schema::table('supplier_invoice_items', function (Blueprint $table) {
            $table->decimal('vat_amount', 12, 2)->nullable();
        });

        Schema::table('supplier_debit_notes', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable();
        });

        Schema::table('supplier_debit_note_items', function (Blueprint $table) {
            $table->decimal('vat_amount', 12, 2)->nullable();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('vat_rate', 5, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });

        Schema::table('supplier_invoice_items', function (Blueprint $table) {
            $table->dropColumn('vat_amount');
        });

        Schema::table('supplier_debit_notes', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });

        Schema::table('supplier_debit_note_items', function (Blueprint $table) {
            $table->dropColumn('vat_amount');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('vat_rate');
        });
    }
};
