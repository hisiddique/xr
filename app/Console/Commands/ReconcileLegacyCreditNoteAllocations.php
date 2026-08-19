<?php

namespace App\Console\Commands;

use App\Services\Migration\LegacyCreditNoteReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('credit-notes:reconcile-legacy-allocations {--dry-run : Preview the changes without writing them}')]
#[Description('Links migrated legacy credit notes to the invoice they were raised against, using the explicit source reference legacy stores on the credit note itself (Documents.srcabbr/srcref), not a guess. Sets documents.credited_invoice_id and writes an unpaid CreditAllocation row. Credit notes whose source reference is missing, points at something other than an invoice, or matches more than one invoice are reported, never guessed. Manual/dev convenience only — a real migration run reconciles automatically when Documents is selected, reusing that run\'s own legacy credentials; this command instead relies on `.env`-configured legacy credentials, so it only works where those are set (e.g. locally).')]
class ReconcileLegacyCreditNoteAllocations extends Command
{
    public function handle(LegacyCreditNoteReconciler $reconciler): int
    {
        $plan = $reconciler->plan();

        if (! empty($plan['ambiguous_refs'])) {
            $this->table(
                ['Legacy Ref', 'Matched Documents Rows'],
                collect($plan['ambiguous_refs'])->map(fn (int $count, string $ref) => [$ref, $count])->all(),
            );
        }

        if ($plan['unresolved_credit_notes']['count'] > 0) {
            $this->line('<comment>Unresolved credit notes (not guessed):</comment> '.$plan['unresolved_credit_notes']['count']);
            $this->table(
                ['Doc Number', 'Reason'],
                collect($plan['unresolved_credit_notes']['sample'])->map(fn (array $row) => [$row['doc_number'], $row['reason']])->all(),
            );
        }

        if ($reconciler->isEmpty($plan)) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d credit note(s) to link to their source invoice, %d allocation row(s) to write, %d excluded (ambiguous ref).',
            count($plan['credited_invoice_updates']),
            count($plan['allocation_rows']),
            count($plan['ambiguous_refs']),
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
            'Reconciliation complete. %d credit note(s) linked, %d allocation row(s) written.',
            count($plan['credited_invoice_updates']),
            count($plan['allocation_rows']),
        ));

        return self::SUCCESS;
    }
}
