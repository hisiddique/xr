<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const array TABLES = [
        'customers',
        'suppliers',
        'documents',
        'document_items',
        'supplier_invoices',
        'supplier_invoice_items',
        'supplier_debit_notes',
        'supplier_debit_note_items',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropIndex("{$table}_legacy_uid_index");
                $blueprint->unique('legacy_uid');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropUnique("{$table}_legacy_uid_unique");
                $blueprint->index('legacy_uid');
            });
        }
    }
};
