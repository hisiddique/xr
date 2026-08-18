<?php

namespace App\Console\Commands;

use App\Services\Migration\LegacyPaymentReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:reconcile-legacy-allocations {--dry-run : Preview the changes without writing them}')]
#[Description('Reconciles migrated legacy payments against migrated invoices. Legacy never recorded which payment paid which invoice — only each invoice\'s current outstanding balance (AccountEntries.Osvalue). This command computes each invoice\'s true target-settled amount from that legacy fact, then funds oldest-invoice-first from that customer\'s oldest migrated payments (an approximation of which payment funded which invoice, not a reconstruction of real history — but the resulting outstanding-per-invoice figure is exact, since it is read directly from legacy, not guessed). Manual/dev convenience only — a real migration run reconciles automatically when both Documents and Customer Payments are selected, reusing that run\'s own legacy credentials; this command instead relies on `.env`-configured legacy credentials, so it only works where those are set (e.g. locally).')]
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

        if (! empty($plan['shortfalls'])) {
            $this->table(
                ['Doc Number', 'Customer ID', 'Shortfall'],
                collect($plan['shortfalls'])->map(fn (array $s) => [
                    $s['doc_number'],
                    $s['customer_id'],
                    number_format($s['shortfall'], 2),
                ])->all(),
            );
        }

        if (! empty($plan['ambiguous_refs'])) {
            $this->table(
                ['Legacy Ref', 'Matched Documents Rows'],
                collect($plan['ambiguous_refs'])->map(fn (int $count, string $ref) => [$ref, $count])->all(),
            );
        }

        if ($reconciler->isEmpty($plan)) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d invoice(s) to settle, %d shortfall(s), %d excluded (ambiguous ref), %d allocation row(s) to write.',
            $plan['to_settle']->count(),
            count($plan['shortfalls']),
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
            'Reconciliation complete. %d invoice(s) settled, %d allocation row(s) written, %d shortfall(s) remain.',
            $plan['to_settle']->count(),
            count($plan['allocation_rows']),
            count($plan['shortfalls']),
        ));

        return self::SUCCESS;
    }
}
