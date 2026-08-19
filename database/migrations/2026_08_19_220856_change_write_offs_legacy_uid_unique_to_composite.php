<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A single legacy write-off entry (AccountEntries.uid) can split across more than
     * one invoice, the same way a payment can — so legacy_uid alone can't be unique;
     * only the pairing with document_id can.
     */
    public function up(): void
    {
        Schema::table('write_offs', function (Blueprint $table) {
            $table->dropUnique(['legacy_uid']);
            $table->unique(['legacy_uid', 'document_id']);
        });
    }

    public function down(): void
    {
        Schema::table('write_offs', function (Blueprint $table) {
            $table->dropUnique(['legacy_uid', 'document_id']);
            $table->unique('legacy_uid');
        });
    }
};
