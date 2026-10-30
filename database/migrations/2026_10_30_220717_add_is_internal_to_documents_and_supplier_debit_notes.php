<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('notes');
        });

        Schema::table('supplier_debit_notes', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });

        Schema::table('supplier_debit_notes', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
