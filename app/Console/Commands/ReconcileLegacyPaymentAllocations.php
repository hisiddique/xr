<?php

namespace App\Console\Commands;

use App\Services\Migration\LegacyPaymentReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:reconcile-legacy-allocations {--dry-run : Preview the changes without writing them}')]
#[Description("Links migrated legacy payments to migrated invoices using legacy's own row-level allocation record (AccountBatchItems — cshuid/txnref/paymt), not a guess. Each invoice's settled status is derived separately and exactly from legacy's AccountEntries.Osvalue, so it's unaffected by allocation resolution. Payments or invoices whose allocation doesn't fully resolve (no record, partial, over-allocated, an orphaned legacy batch item, or an invoice whose allocations don't match its legacy balance) are reported, never force-balanced. Manual/dev convenience only — a real migration run reconciles automatically when both Documents and Customer Payments are selected, reusing that run's own legacy credentials; this command instead relies on `.env`-configured legacy credentials, so it only works where those are set (e.g. locally).")]
class ReconcileLegacyPaymentAllocations extends Command
{
    public function handle(LegacyPaymentReconciler $reconciler): int
    {
        $plan = $reconciler->plan();

        if ($plan['to_settle']->isNotEmpty()) {
            $this->table(
                ['Doc Number', 'Target Settled Amount'],
                $plan['to_settle']->map(fn (array $t) => [
                    $t['doc_number'],
                    number_format($t['target_settled'], 2),
                ])->all(),
            );
        }

        if (! empty($plan['ambiguous_refs'])) {
            $this->table(
                ['Legacy Ref', 'Matched Documents Rows'],
                collect($plan['ambiguous_refs'])->map(fn (int $count, string $ref) => [$ref, $count])->all(),
            );
        }

        foreach (['unallocated_payments' => 'Unallocated Payments', 'partially_allocated_payments' => 'Partially Allocated Payments', 'over_allocated_payments' => 'Over-Allocated Payments'] as $key => $label) {
            if ($plan[$key]['count'] > 0) {
                $this->line("<comment>{$label}:</comment> {$plan[$key]['count']} (showing up to ".count($plan[$key]['sample']).')');
                $this->table(
                    ['Legacy UID', 'Reference', 'Amount', 'Allocated'],
                    collect($plan[$key]['sample'])->map(fn (array $row) => [
                        $row['legacy_uid'],
                        $row['reference'] ?? '—',
                        number_format($row['amount'], 2),
                        isset($row['allocated']) ? number_format($row['allocated'], 2) : '—',
                    ])->all(),
                );
            }
        }

        if ($plan['orphaned_batch_items']['count'] > 0) {
            $this->warn("Orphaned legacy batch items (no matching migrated payment): {$plan['orphaned_batch_items']['count']}");
        }

        if ($plan['invoice_target_mismatches']['count'] > 0) {
            $this->line('<comment>Invoices whose allocation total does not match their legacy balance:</comment> '.$plan['invoice_target_mismatches']['count']);
            $this->table(
                ['Doc Number', 'Target Settled', 'Allocated'],
                collect($plan['invoice_target_mismatches']['sample'])->map(fn (array $row) => [
                    $row['doc_number'],
                    number_format($row['target_settled'], 2),
                    number_format($row['allocated'], 2),
                ])->all(),
            );
        }

        if ($reconciler->isEmpty($plan)) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d invoice(s) to settle, %d excluded (ambiguous ref), %d allocation row(s) to write.',
            $plan['to_settle']->count(),
            count($plan['ambiguous_refs']),
            count($plan['allocation_rows']),
        ));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $reconciler->apply($plan);

        $this->info(sprintf(
            'Reconciliation complete. %d invoice(s) settled, %d allocation row(s) written.',
            $plan['to_settle']->count(),
            count($plan['allocation_rows']),
        ));

        return self::SUCCESS;
    }
}
