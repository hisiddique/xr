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
        Schema::create('statement_schedules', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('model_type'); // customer | supplier
            $table->string('status')->default('draft'); // draft | active | paused
            $table->string('frequency'); // monthly | month_end | weekly | quarterly | one_time

            $table->foreignId('customer_group_id')->nullable()->constrained('customer_groups')->restrictOnDelete();
            $table->foreignId('supplier_group_id')->nullable()->constrained('supplier_groups')->restrictOnDelete();
            $table->foreignId('exclude_customer_group_id')->nullable()->constrained('customer_groups')->restrictOnDelete();
            $table->foreignId('exclude_supplier_group_id')->nullable()->constrained('supplier_groups')->restrictOnDelete();

            $table->time('run_time'); // interpreted in the app timezone
            $table->unsignedTinyInteger('day_of_month')->nullable(); // 1..28 (monthly)
            $table->unsignedTinyInteger('day_of_week')->nullable();  // ISO 1=Mon..7=Sun (weekly)
            $table->date('anchor_date')->nullable();                 // one_time run date; quarterly first-run anchor

            $table->json('rules');
            $table->boolean('notify_enabled')->default(false);
            $table->json('notify_emails')->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'next_run_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statement_schedules');
    }
};
