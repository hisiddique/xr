<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SupplierDebitNote;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('supplier-debit-notes:backfill-vat {--dry-run : Preview the changes without writing them}')]
#[Description('Backfills vat_amount/total on supplier debit notes created before VAT support: recalculates subtotal * vat_rate for every note whose supplier has vat_applied enabled and whose vat_amount is still 0, and keeps any applied-invoice pivot amount in sync.')]
class BackfillSupplierDebitNoteVat extends Command
{
    public function handle(): int
    {
        $vatRate = (float) Setting::get('vat_rate', 20);

        $notes = SupplierDebitNote::with(['supplier', 'appliedInvoices'])
            ->where('vat_amount', 0)
            ->get()
            ->filter(fn (SupplierDebitNote $note) => (bool) $note->supplier?->vat_applied);

        if ($notes->isEmpty()) {
            $this->info('Nothing to backfill — no debit notes need a VAT recalculation.');

            return self::SUCCESS;
        }

        $rows = $notes->map(function (SupplierDebitNote $note) use ($vatRate) {
            $vatAmount = round((float) $note->subtotal * $vatRate / 100, 2);

            return [
                'reference' => $note->reference,
                'supplier' => $note->supplier->company_name,
                'subtotal' => number_format((float) $note->subtotal, 2),
                'vat_amount' => number_format($vatAmount, 2),
                'old_total' => number_format((float) $note->total, 2),
                'new_total' => number_format((float) $note->subtotal + $vatAmount, 2),
            ];
        });

        $this->table(['Reference', 'Supplier', 'Subtotal', 'VAT', 'Old Total', 'New Total'], $rows);
        $this->info("{$notes->count()} debit note(s) will be updated using a VAT rate of {$vatRate}%.");

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($notes, $vatRate) {
            foreach ($notes as $note) {
                $vatAmount = round((float) $note->subtotal * $vatRate / 100, 2);
                $oldTotal = (float) $note->total;
                $newTotal = round((float) $note->subtotal + $vatAmount, 2);

                $note->update(['vat_amount' => $vatAmount, 'total' => $newTotal]);

                foreach ($note->appliedInvoices as $invoice) {
                    if (abs((float) $invoice->pivot->applied_amount - $oldTotal) < 0.001) {
                        $note->appliedInvoices()->updateExistingPivot($invoice->id, ['applied_amount' => $newTotal]);
                    }
                }
            }
        });

        $this->info("Backfill complete. {$notes->count()} debit note(s) updated.");

        return self::SUCCESS;
    }
}
