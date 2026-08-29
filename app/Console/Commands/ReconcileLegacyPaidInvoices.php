<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\User;
use App\Services\Migration\LegacyPaidInvoiceReconciler;
use App\UserRole;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:reconcile-legacy-paid-invoices {--as-of=2025-08-31 : Only invoices dated on or before this date are in scope} {--exclude-reference=MORR : customers.reference to exclude entirely (blank to disable)} {--user= : id or email to record as created_by / written_off_by (defaults to the first admin)} {--customer= : Restrict the run to a single customer (id or reference) — canary} {--chunk=2000 : Keyset page size} {--dry-run : Compute and show the summary without writing anything} {--revert= : Undo a previous run by its batch id (soft-deletes its payments, allocations and write-offs)}')]
#[Description('Reconciles legacy customer invoices confirmed paid in the old system. Path A (no existing allocation) gets a synthetic cash payment plus a full allocation; Path B (already partly allocated) gets a write-off for the residual. Invoices with a live write-off, or belonging to the excluded customer, are left alone. Every row written is tagged with a batch id for --revert.')]
class ReconcileLegacyPaidInvoices extends Command
{
    public function handle(): int
    {
        $user = $this->resolveUser();

        if ($user === null) {
            $this->error('No user to attribute rows to. Pass --user=<id|email> or create an admin user.');

            return self::FAILURE;
        }

        $excludeCustomerId = null;
        $excludeReference = trim((string) $this->option('exclude-reference'));

        if ($excludeReference !== '') {
            $excludeCustomerId = Customer::where('reference', $excludeReference)->value('id');

            if ($excludeCustomerId === null) {
                $this->error("--exclude-reference \"{$excludeReference}\" matches no customer. Refusing to run so that customer is not reconciled by mistake.");

                return self::FAILURE;
            }
        }

        $onlyCustomerId = $this->resolveOnlyCustomerId();

        if ($onlyCustomerId === false) {
            return self::FAILURE;
        }

        $reconciler = new LegacyPaidInvoiceReconciler(
            excludeCustomerId: $excludeCustomerId,
            onlyCustomerId: $onlyCustomerId,
            userId: $user->id,
            asOf: (string) $this->option('as-of'),
            chunkSize: max(1, (int) $this->option('chunk')),
        );

        $revertBatch = trim((string) $this->option('revert'));

        if ($revertBatch !== '') {
            return $this->revert($reconciler, $revertBatch);
        }

        $batch = now()->format('Ymd-His');

        if ($this->option('dry-run')) {
            $counts = $reconciler->run($batch, commit: false);
            $this->renderSummary($counts);
            $this->comment('Dry run — nothing written.');

            return self::SUCCESS;
        }

        $this->line("Batch <info>{$batch}</info> — record this value to use --revert later.");

        if (! $this->confirm('Create payments / write-offs for the invoices above?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $counts = $reconciler->run($batch, commit: true);
        $this->renderSummary($counts);
        $this->info("Done. Batch {$batch}.");

        return self::SUCCESS;
    }

    private function resolveUser(): ?User
    {
        $option = trim((string) $this->option('user'));

        if ($option !== '') {
            return is_numeric($option)
                ? User::find((int) $option)
                : User::where('email', $option)->first();
        }

        return User::where('role', UserRole::Admin)->orderBy('id')->first();
    }

    /**
     * @return int|null|false int = restrict to this customer, null = no restriction, false = unresolved (abort)
     */
    private function resolveOnlyCustomerId(): int|null|false
    {
        $option = trim((string) $this->option('customer'));

        if ($option === '') {
            return null;
        }

        $id = is_numeric($option)
            ? Customer::whereKey((int) $option)->value('id')
            : Customer::where('reference', $option)->value('id');

        if ($id === null) {
            $this->error("--customer \"{$option}\" matches no customer.");

            return false;
        }

        return $id;
    }

    private function revert(LegacyPaidInvoiceReconciler $reconciler, string $batch): int
    {
        $result = $reconciler->revert($batch);

        $this->info(sprintf(
            'Reverted batch %s: %d payment(s), %d allocation(s), %d write-off(s) soft-deleted.',
            $batch,
            $result['payments'],
            $result['allocations'],
            $result['write_offs'],
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array{path_a_count: int, path_a_total: float, path_a_samples: array<int, string>, path_b_count: int, path_b_total: float, path_b_samples: array<int, string>, skipped_settled: int, skipped_write_off_present?: int}  $counts
     */
    private function renderSummary(array $counts): void
    {
        $this->table(['Bucket', 'Count', 'Amount', 'Sample'], [
            ['Path A — payments created', $counts['path_a_count'], number_format($counts['path_a_total'], 2), implode(', ', $counts['path_a_samples'])],
            ['Path B — residuals written off', $counts['path_b_count'], number_format($counts['path_b_total'], 2), implode(', ', $counts['path_b_samples'])],
            ['Skipped — already settled', $counts['skipped_settled'], '—', ''],
            ['Skipped — write-off present', $counts['skipped_write_off_present'] ?? '—', '—', ''],
        ]);
    }
}
