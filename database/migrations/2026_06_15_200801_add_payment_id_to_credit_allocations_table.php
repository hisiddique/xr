<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('credit_allocations', function (Blueprint $table) {
            $table->dropForeign(['credit_note_id']);
            $table->dropForeign(['invoice_id']);
            $table->dropUnique(['credit_note_id', 'invoice_id']);
            $table->foreign('credit_note_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->unique(['payment_id', 'credit_note_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_allocations', function (Blueprint $table) {
            $table->dropUnique(['payment_id', 'credit_note_id', 'invoice_id']);
            $table->dropConstrainedForeignId('payment_id');
            $table->dropForeign(['credit_note_id']);
            $table->dropForeign(['invoice_id']);
            $table->unique(['credit_note_id', 'invoice_id']);
            $table->foreign('credit_note_id')->references('id')->on('documents')->nullOnDelete();
            $table->foreign('invoice_id')->references('id')->on('documents')->nullOnDelete();
        });
    }
};
