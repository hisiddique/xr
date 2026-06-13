<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('type', 10)->change();
            $table->text('reason')->nullable()->after('status');
            $table->foreignId('credited_invoice_id')->nullable()->after('converted_from_id')->constrained('documents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credited_invoice_id');
            $table->dropColumn('reason');
            $table->enum('type', ['DN', 'INV'])->change();
        });
    }
};
