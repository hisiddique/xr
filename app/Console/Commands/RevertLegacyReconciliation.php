<?php

namespace App\Console\Commands;

use App\Services\Migration\LegacyOutstandingReconciler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:revert-legacy-recon {batch : The batch id to undo, e.g. MIGRATION-42}')]
#[Description('Undoes the synthetic payments, allocations and write-offs a LegacyOutstandingReconciler run tagged with the given batch id. Does NOT restore allocations shrunk or soft-deleted by the over-applied reduce path, nor un-exhaust payments it marked exhausted.')]
class RevertLegacyReconciliation extends Command
{
    public function handle(): int
    {
        $batch = trim((string) $this->argument('batch'));

        if ($batch === '') {
            $this->error('A batch id is required.');

            return self::FAILURE;
        }

        if (! $this->confirm("Soft-delete every payment / allocation / write-off tagged {$batch}?", true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $result = (new LegacyOutstandingReconciler(0, null))->revert($batch);

        $this->info(sprintf(
            'Reverted %s: %d payment(s), %d allocation(s), %d write-off(s) soft-deleted.',
            $batch,
            $result['payments'],
            $result['allocations'],
            $result['write_offs'],
        ));

        return self::SUCCESS;
    }
}
