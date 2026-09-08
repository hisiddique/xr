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
        Schema::create('statement_dispatch_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_schedule_id')->nullable()->constrained('statement_schedules')->nullOnDelete();
            $table->string('schedule_name'); // snapshot, survives schedule deletion
            $table->foreignId('parent_run_id')->nullable()->constrained('statement_dispatch_runs')->nullOnDelete();
            $table->string('model_type'); // customer | supplier
            $table->string('trigger'); // scheduled | manual | retry
            $table->string('status')->default('queued'); // queued | processing | completed | completed_with_errors | failed
            $table->timestamp('scheduled_for')->nullable(); // set only for scheduled runs
            $table->date('period_from')->nullable();
            $table->date('period_to')->nullable();
            $table->string('period_label')->nullable();
            $table->json('rules_snapshot');
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['statement_schedule_id', 'created_at'], 'stmt_runs_schedule_created_idx');
            // idempotency guard; NULLs don't collide, so manual/retry runs are unconstrained
            $table->unique(['statement_schedule_id', 'scheduled_for'], 'stmt_runs_schedule_occurrence_uq');
        });

        Schema::create('statement_dispatch_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('statement_dispatch_run_id')->constrained('statement_dispatch_runs')->cascadeOnDelete();
            $table->string('recipient_type'); // customer | supplier
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained('suppliers')->nullOnDelete();
            $table->string('recipient_name');
            $table->string('recipient_email')->nullable();
            $table->string('status')->default('pending'); // pending | sent | failed | skipped
            $table->string('skip_reason')->nullable(); // no_email | no_activity
            $table->text('error_message')->nullable();
            $table->decimal('outstanding_total', 15, 2)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['statement_dispatch_run_id', 'status'], 'stmt_run_items_run_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('statement_dispatch_run_items');
        Schema::dropIfExists('statement_dispatch_runs');
    }
};
