<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();
            $table->decimal('amount', 15, 2);
            $table->text('reason');
            $table->dateTime('written_off_at');
            $table->foreignId('written_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('legacy_uid')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('write_offs');
    }
};
