<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_debit_note_items', function (Blueprint $table) {
            $table->string('per', 20)->nullable()->after('amount');
            $table->boolean('is_note')->default(false)->after('per');
            $table->decimal('discount_percent', 6, 2)->default(0)->after('is_note');
            $table->decimal('line_value', 12, 2)->default(0)->after('discount_percent');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_debit_note_items', function (Blueprint $table) {
            $table->dropColumn(['per', 'is_note', 'discount_percent', 'line_value']);
        });
    }
};
