<?php

namespace App\Jobs;

use App\MigrationRunStatus;
use App\Models\MigrationRun;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\LegacyCreditNoteReconciler;
use App\Services\Migration\LegacyPaymentReconciler;
use App\Services\Migration\LegacyWriteOffReconciler;
use App\Services\Migration\MigrationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class RunLegacyMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 14400;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $selectedGroups
     */
    public function __construct(
        private readonly int $migrationRunId,
        private readonly array $selectedGroups,
        private readonly string $duplicateStrategy,
        private readonly string $clearMode,
        private readonly int $createdByUserId,
    ) {
        $this->onQueue('migrations');
    }

    public function handle(): void
    {
        $run = MigrationRun::findOrFail($this->migrationRunId);

        $this->applyLegacyCredentials($run);

        try {
            (new MigrationRunner($run))->run(
                $this->selectedGroups,
                DuplicateStrategy::from($this->duplicateStrategy),
                $this->clearMode,
                $this->createdByUserId,
            );

            $this->reconcilePaymentsIfApplicable($run);
            $this->reconcileCreditNotesIfApplicable($run);
            $this->reconcileWriteOffsIfApplicable($run);
        } catch (\Throwable $e) {
            // MigrationRunner already marks the run Failed on per-row/per-mapper
            // failures; this only catches an unexpected escape (e.g. a lost DB
            // connection) so the run record doesn't stay stuck at Running.
            $run->update([
                'status' => MigrationRunStatus::Failed,
                'error' => Str::limit($e->getMessage(), 2000),
                'finished_at' => now(),
            ]);

            throw $e;
        } finally {
            // Credentials were only needed to open the legacy connection for this run;
            // clear them once the run reaches a terminal state, success or failure.
            $run->update(['legacy_credentials' => null]);
        }
    }

    /**
     * Links migrated payments to migrated invoices right after a successful run, only
     * when both were part of this run — it needs both to exist and reuses this same
     * request's already-authenticated 'legacy' connection (still open at this point,
     * before applyLegacyCredentials()'s finally block clears the stored credentials),
     * since production never persists the legacy DB password anywhere a standalone
     * command could reach it later.
     *
     * Failure here doesn't fail the migration itself — the migration already
     * succeeded independently of this step — but is recorded on the run for
     * visibility rather than silently swallowed.
     */
    private function reconcilePaymentsIfApplicable(MigrationRun $run): void
    {
        if (! in_array('documents', $this->selectedGroups, true) || ! in_array('payments', $this->selectedGroups, true)) {
            return;
        }

        if ($run->fresh()->status !== MigrationRunStatus::Completed) {
            return;
        }

        try {
            $reconciler = app(LegacyPaymentReconciler::class);
            $plan = $reconciler->plan();

            if ($reconciler->isEmpty($plan)) {
                return;
            }

            $reconciler->apply($plan);

            $run->update(['options' => array_merge($run->options ?? [], [
                'reconciliation' => [
                    'settled' => $plan['to_settle']->count(),
                    'unsettled' => $plan['to_unsettle']->count(),
                    'allocation_rows' => count($plan['allocation_rows']),
                    'ambiguous_refs' => count($plan['ambiguous_refs']),
                    'unallocated_payments' => $plan['unallocated_payments']['count'],
                    'partially_allocated_payments' => $plan['partially_allocated_payments']['count'],
                    'over_allocated_payments' => $plan['over_allocated_payments']['count'],
                    'orphaned_batch_items' => $plan['orphaned_batch_items']['count'],
                    'invoice_target_mismatches' => $plan['invoice_target_mismatches']['count'],
                ],
            ])]);
        } catch (\Throwable $e) {
            Log::warning('Legacy payment reconciliation failed after a successful migration run', [
                'migration_run_id' => $run->id,
                'message' => $e->getMessage(),
            ]);

            $run->update(['options' => array_merge($run->options ?? [], [
                'reconciliation_error' => Str::limit($e->getMessage(), 500),
            ])]);
        }
    }

    /**
     * Links migrated credit notes to the invoice they were raised against — see
     * LegacyCreditNoteReconciler's docblock. Runs whenever 'documents' was migrated;
     * unlike payment reconciliation this doesn't need 'payments' in the same run.
     */
    private function reconcileCreditNotesIfApplicable(MigrationRun $run): void
    {
        if (! in_array('documents', $this->selectedGroups, true)) {
            return;
        }

        if ($run->fresh()->status !== MigrationRunStatus::Completed) {
            return;
        }

        try {
            $reconciler = app(LegacyCreditNoteReconciler::class);
            $plan = $reconciler->plan();

            if ($reconciler->isEmpty($plan)) {
                return;
            }

            $reconciler->apply($plan);

            $run->update(['options' => array_merge($run->options ?? [], [
                'credit_note_reconciliation' => [
                    'credited_invoice_updates' => count($plan['credited_invoice_updates']),
                    'allocation_rows' => count($plan['allocation_rows']),
                    'ambiguous_refs' => count($plan['ambiguous_refs']),
                    'unresolved_credit_notes' => $plan['unresolved_credit_notes']['count'],
                ],
            ])]);
        } catch (\Throwable $e) {
            Log::warning('Legacy credit note reconciliation failed after a successful migration run', [
                'migration_run_id' => $run->id,
                'message' => $e->getMessage(),
            ]);

            $run->update(['options' => array_merge($run->options ?? [], [
                'credit_note_reconciliation_error' => Str::limit($e->getMessage(), 500),
            ])]);
        }
    }

    /**
     * Migrates legacy write-offs into `write_offs` rows — see LegacyWriteOffReconciler's
     * docblock. Runs whenever 'documents' was migrated, same trigger as credit notes.
     */
    private function reconcileWriteOffsIfApplicable(MigrationRun $run): void
    {
        if (! in_array('documents', $this->selectedGroups, true)) {
            return;
        }

        if ($run->fresh()->status !== MigrationRunStatus::Completed) {
            return;
        }

        try {
            $reconciler = new LegacyWriteOffReconciler($this->createdByUserId);
            $plan = $reconciler->plan();

            if ($reconciler->isEmpty($plan)) {
                return;
            }

            $reconciler->apply($plan);

            $run->update(['options' => array_merge($run->options ?? [], [
                'write_off_reconciliation' => [
                    'write_off_rows' => count($plan['write_off_rows']),
                    'ambiguous_refs' => count($plan['ambiguous_refs']),
                    'unresolved_write_offs' => $plan['unresolved_write_offs']['count'],
                ],
            ])]);
        } catch (\Throwable $e) {
            Log::warning('Legacy write-off reconciliation failed after a successful migration run', [
                'migration_run_id' => $run->id,
                'message' => $e->getMessage(),
            ]);

            $run->update(['options' => array_merge($run->options ?? [], [
                'write_off_reconciliation_error' => Str::limit($e->getMessage(), 500),
            ])]);
        }
    }

    /**
     * If per-run legacy DB credentials were supplied (production hosts may need
     * different credentials than whatever is baked into .env at deploy time),
     * override the 'legacy' connection config for this process before any mapper
     * touches DB::connection('legacy'). Falls back to .env-configured credentials
     * when none were supplied for this run.
     */
    private function applyLegacyCredentials(MigrationRun $run): void
    {
        $credentials = $run->legacy_credentials;

        if (! $credentials) {
            return;
        }

        config(['database.connections.legacy' => array_merge(
            config('database.connections.legacy', []),
            [
                'host' => $credentials['host'],
                'port' => $credentials['port'] ?: 1433,
                'database' => $credentials['database'],
                'username' => $credentials['username'],
                'password' => $credentials['password'],
            ],
        )]);

        DB::purge('legacy');
    }
}
