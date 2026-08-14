<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SupplierDebitNote;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('supplier-debit-notes:backfill-vat {--dry-run : Preview the changes without writing them}')]
#[Description('Backfills VAT on supplier debit notes created before per-item VAT support: recalculates subtotal * vat_rate for every note whose supplier has vat_applied enabled and whose vat_amount is still 0, keeps any applied-invoice pivot amount in sync, and syncs each line item\'s vat_applicable flag with its note\'s resulting vat_amount.')]
class BackfillSupplierDebitNoteVat extends Command
{
    public function handle(): int
    {
        $vatRate = (float) Setting::get('vat_rate', 20);

        $notesToFix = SupplierDebitNote::with(['supplier', 'appliedInvoices'])
            ->where('vat_amount', 0)
            ->get()
            ->filter(fn (SupplierDebitNote $note) => (bool) $note->supplier?->vat_applied);

        if ($notesToFix->isNotEmpty()) {
            $rows = $notesToFix->map(function (SupplierDebitNote $note) use ($vatRate) {
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
            $this->info("{$notesToFix->count()} debit note(s) will have their VAT recalculated using a VAT rate of {$vatRate}%.");
        }

        // Notes not in $notesToFix already have their final vat_amount; notes in
        // $notesToFix will land on the recalculated amount above once applied.
        $finalVatAmountFor = fn (SupplierDebitNote $note): float => $notesToFix->contains($note)
            ? round((float) $note->subtotal * $vatRate / 100, 2)
            : (float) $note->vat_amount;

        $itemsToFix = SupplierDebitNote::with('items')->get()
            ->flatMap(function (SupplierDebitNote $note) use ($finalVatAmountFor) {
                $expected = $finalVatAmountFor($note) > 0;

                return $note->items
                    ->filter(fn ($item) => (bool) $item->vat_applicable !== $expected)
                    ->map(fn ($item) => ['item' => $item, 'note' => $note, 'expected' => $expected]);
            });

        if ($itemsToFix->isNotEmpty()) {
            $rows = $itemsToFix->map(fn (array $row) => [
                'reference' => $row['note']->reference,
                'item' => $row['item']->description,
                'from' => $row['item']->vat_applicable ? 'Yes' : 'No',
                'to' => $row['expected'] ? 'Yes' : 'No',
            ]);

            $this->table(['Reference', 'Item', 'From', 'To'], $rows);
            $this->info("{$itemsToFix->count()} line item(s) will have their vat_applicable flag synced with their note's VAT status.");
        }

        if ($notesToFix->isEmpty() && $itemsToFix->isEmpty()) {
            $this->info('Nothing to backfill — VAT is already in sync.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($notesToFix, $itemsToFix, $vatRate) {
            foreach ($notesToFix as $note) {
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

            foreach ($itemsToFix as $row) {
                $row['item']->update(['vat_applicable' => $row['expected']]);
            }
        });

        $this->info("Backfill complete. {$notesToFix->count()} debit note(s) and {$itemsToFix->count()} line item(s) updated.");

        return self::SUCCESS;
    }
}
