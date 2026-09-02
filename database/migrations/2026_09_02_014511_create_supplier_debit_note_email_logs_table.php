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
        Schema::create('supplier_debit_note_email_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_debit_note_id')->constrained()->cascadeOnDelete();
            $table->string('recipient_email');
            $table->json('recipient_emails')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->enum('status', ['sent', 'failed'])->default('sent');
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_debit_note_email_logs');
    }
};
