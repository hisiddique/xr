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
        Schema::create('supplier_invoice_debit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->foreignId('supplier_debit_note_id')->constrained('supplier_debit_notes')->cascadeOnDelete();
            $table->decimal('applied_amount', 15, 2);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique(['supplier_invoice_id', 'supplier_debit_note_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_debit_notes');
    }
};
