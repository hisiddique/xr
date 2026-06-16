<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('credit_note_id')->constrained('documents')->nullOnDelete();
            $table->foreignId('invoice_id')->constrained('documents')->nullOnDelete();
            $table->decimal('amount', 15, 2);
            $table->timestamps();
            $table->unique(['credit_note_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_allocations');
    }
};
