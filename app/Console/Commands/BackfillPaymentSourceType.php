<?php

namespace App\Console\Commands;

use App\Models\Payment;
use App\PaymentSourceType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:backfill-source-type {--dry-run : Preview the changes without writing them}')]
#[Description('Backfills payments.source_type for historical payments: defaults every payment to cash, then relinks any payment that was purely a credit-clearing entry (has credit allocations but no cash allocated to any invoice) to the Credit Notes source type.')]
class BackfillPaymentSourceType extends Command
{
    public function handle(): int
    {
        $defaulted = Payment::whereNull('source_type')->count();

        $creditOnlyIds = Payment::query()
            ->whereHas('creditAllocations')
            ->doesntHave('allocations')
            ->where('source_type', '!=', PaymentSourceType::CreditNote)
            ->pluck('id');

        if ($defaulted === 0 && $creditOnlyIds->isEmpty()) {
            $this->info('Nothing to backfill — all payments already have a source_type.');

            return self::SUCCESS;
        }

        $this->info("{$defaulted} payment(s) will default to 'cash'.");
        $this->info("{$creditOnlyIds->count()} payment(s) will relink to 'credit_note' (credit-funded, no cash allocations).");

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        Payment::whereNull('source_type')->update(['source_type' => PaymentSourceType::Cash]);

        if ($creditOnlyIds->isNotEmpty()) {
            Payment::whereIn('id', $creditOnlyIds)->update(['source_type' => PaymentSourceType::CreditNote]);
        }

        $this->info('Backfill complete.');

        return self::SUCCESS;
    }
}
