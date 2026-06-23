<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->renameColumn('reason', 'notes');
            $table->dropColumn('global_amount');
        });

        Schema::table('document_items', function (Blueprint $table) {
            $table->decimal('discount_percent', 5, 2)->default(0)->after('line_value');
            $table->renameColumn('refund_amount', 'net_value');
            $table->dropColumn('original_amount');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->renameColumn('notes', 'reason');
            $table->decimal('global_amount', 15, 2)->default(0);
        });

        Schema::table('document_items', function (Blueprint $table) {
            $table->dropColumn('discount_percent');
            $table->renameColumn('net_value', 'refund_amount');
            $table->decimal('original_amount', 15, 2)->nullable();
        });
    }
};
