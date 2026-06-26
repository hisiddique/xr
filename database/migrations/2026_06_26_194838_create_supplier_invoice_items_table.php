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
        Schema::create('supplier_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_invoice_id')->constrained('supplier_invoices')->cascadeOnDelete();
            $table->string('product_code')->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_amount', 12, 2)->default(0);
            $table->boolean('vat_applicable')->default(false);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_invoice_items');
    }
};
