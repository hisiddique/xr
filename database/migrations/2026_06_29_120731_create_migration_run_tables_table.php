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
        Schema::create('migration_run_tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
            $table->string('entity');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('rows_total')->default(0);
            $table->unsignedBigInteger('rows_processed')->default(0);
            $table->unsignedBigInteger('added')->default(0);
            $table->unsignedBigInteger('updated')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->unsignedBigInteger('failed')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['migration_run_id', 'entity']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('migration_run_tables');
    }
};
