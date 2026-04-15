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
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->enum('type', ['DN', 'INV'])->index();
            $table->string('doc_number', 20)->unique()->nullable();
            $table->string('order_no', 50)->nullable();
            $table->date('doc_date');
            $table->decimal('subtotal', 15, 2)->default(0.00);
            $table->decimal('trade_discount', 5, 2)->default(0.00);
            $table->decimal('discount_amount', 15, 2)->default(0.00);
            $table->decimal('vat_amount', 15, 2)->default(0.00);
            $table->decimal('total_value', 15, 2)->default(0.00);
            $table->enum('status', ['active', 'converted', 'emailed'])->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('converted_from_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['type', 'status']);
            $table->index(['customer_id', 'doc_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
