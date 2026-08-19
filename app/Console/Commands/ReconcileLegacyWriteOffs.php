<?php

namespace App\Console\Commands;

use App\Services\Migration\LegacyWriteOffReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('write-offs:reconcile-legacy {--dry-run : Preview the changes without writing them}')]
#[Description("Migrates legacy write-offs (AccountEntries.posttype=94) into write_offs rows, resolving the invoice each applies to via AccountBatchItems (cshuid/txnref/paymt) — the same mechanism payments use — since a write-off's own invno is just the free-text label \"W'Off\", not a usable reference. Legacy never recorded a reason, so migrated rows get a fixed placeholder reason. Write-offs whose batch item is missing, ambiguous, or doesn't resolve to a migrated invoice are reported, never guessed. Manual/dev convenience only — a real migration run reconciles automatically when Documents is selected, reusing that run's own legacy credentials; this command instead relies on `.env`-configured legacy credentials, so it only works where those are set (e.g. locally).")]
class ReconcileLegacyWriteOffs extends Command
{
    public function handle(): int
    {
        $reconciler = new LegacyWriteOffReconciler(auth()->id());
        $plan = $reconciler->plan();

        if (! empty($plan['ambiguous_refs'])) {
            $this->table(
                ['Legacy Ref', 'Matched Documents Rows'],
                collect($plan['ambiguous_refs'])->map(fn (int $count, string $ref) => [$ref, $count])->all(),
            );
        }

        if ($plan['unresolved_write_offs']['count'] > 0) {
            $this->line('<comment>Unresolved write-offs (not guessed):</comment> '.$plan['unresolved_write_offs']['count']);
            $this->table(
                ['Legacy UID'],
                collect($plan['unresolved_write_offs']['sample'])->map(fn (array $row) => [$row['legacy_uid']])->all(),
            );
        }

        if ($reconciler->isEmpty($plan)) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d write-off(s) to write, %d excluded (ambiguous ref).',
            count($plan['write_off_rows']),
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

        $this->info(sprintf('Reconciliation complete. %d write-off(s) written.', count($plan['write_off_rows'])));

        return self::SUCCESS;
    }
}
