<?php

namespace App\Console\Commands;

use App\Services\Migration\LegacyOutstandingReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:revert-legacy-recon {batch : The batch id to undo, e.g. MIGRATION-42}')]
#[Description('Clears the legacy_confirmed_paid flag a LegacyOutstandingReconciler run set on invoices tagged with the given batch id. Does NOT restore allocations shrunk or soft-deleted by the over-applied reduce path, nor un-exhaust payments it marked exhausted.')]
class RevertLegacyReconciliation extends Command
{
    public function handle(): int
    {
        $batch = trim((string) $this->argument('batch'));

        if ($batch === '') {
            $this->error('A batch id is required.');

            return self::FAILURE;
        }

        if (! $this->confirm("Clear the legacy_confirmed_paid flag on every invoice tagged {$batch}?", true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $result = (new LegacyOutstandingReconciler(0, null))->revert($batch);

        $this->info(sprintf('Reverted %s: %d flag(s) cleared.', $batch, $result['flags_cleared']));

        return self::SUCCESS;
    }
}
