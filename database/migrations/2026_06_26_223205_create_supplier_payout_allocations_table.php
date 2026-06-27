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
        Schema::create('supplier_payout_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_payout_id')->constrained('supplier_payouts')->cascadeOnDelete();
            $table->foreignId('supplier_invoice_id')->nullable()->constrained('supplier_invoices')->nullOnDelete();
            $table->foreignId('supplier_debit_note_id')->nullable()->constrained('supplier_debit_notes')->nullOnDelete();
            $table->decimal('deduction_amount', 15, 2)->default(0);
            $table->decimal('allocated_amount', 15, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_payout_allocations');
    }
};
