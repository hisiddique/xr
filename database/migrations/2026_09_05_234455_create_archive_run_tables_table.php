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
        Schema::create('archive_run_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_run_id')->constrained('archive_runs')->cascadeOnDelete();
            $table->string('entity');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('rows_total')->default(0);
            $table->unsignedBigInteger('rows_copied')->default(0);
            $table->unsignedBigInteger('rows_deleted')->default(0);
            $table->unsignedBigInteger('rows_skipped')->default(0);
            $table->unsignedBigInteger('rows_failed')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['archive_run_id', 'entity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('archive_run_tables');
    }
};
