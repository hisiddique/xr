<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            $table->decimal('original_amount', 15, 2)->nullable()->after('line_value');
            $table->decimal('refund_amount', 15, 2)->default(0.00)->after('original_amount');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->decimal('global_amount', 15, 2)->default(0.00)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('document_items', function (Blueprint $table) {
            $table->dropColumn(['refund_amount', 'original_amount']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('global_amount');
        });
    }
};
