<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Services\DocumentTotalsCalculator;
use App\Services\SupplierInvoiceTotalsCalculator;
use App\Support\Money;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('supplier-debit-notes:backfill-deductions {--dry-run : Preview the changes without writing them}')]
#[Description('Recalculates the lines and totals of VAT-inclusive supplier debit notes with the current formula, then recalculates the applied amount of every debit note attached to a supplier invoice, writing only what differs.')]
class BackfillSupplierDebitNoteDeductions extends Command
{
    public function handle(): int
    {
        DB::beginTransaction();

        $noteChanges = $this->recalculateInclusiveNotes();
        $deductionChanges = $this->recalculateDeductions();

        if ($noteChanges === [] && $deductionChanges === []) {
            DB::rollBack();
            $this->info('Nothing to backfill — debit notes and applied amounts are already in sync.');

            return self::SUCCESS;
        }

        if ($noteChanges !== []) {
            $this->table(['Debit Note', 'Old Total', 'New Total'], array_map(fn (array $c) => [
                $c['note'], number_format($c['old'], 2), number_format($c['new'], 2),
            ], $noteChanges));
            $this->info(count($noteChanges).' debit note(s) will be recalculated.');
        }

        if ($deductionChanges !== []) {
            $this->table(['Invoice', 'Debit Note', 'Old Applied', 'New Applied'], array_map(fn (array $c) => [
                $c['invoice'], $c['note'], number_format($c['old'], 2), number_format($c['new'], 2),
            ], $deductionChanges));
            $this->info(count($deductionChanges).' applied amount(s) will change.');
        }

        if ($this->option('dry-run')) {
            DB::rollBack();
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            DB::rollBack();
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        DB::commit();
        $this->info('Backfill complete. '.count($noteChanges).' debit note(s) and '.count($deductionChanges).' applied amount(s) updated.');

        return self::SUCCESS;
    }

    /**
     * @return array<int, array{note: string, old: float, new: float}>
     */
    private function recalculateInclusiveNotes(): array
    {
        $changes = [];

        SupplierDebitNote::query()->where('vat_inclusive', true)->with('items')->each(function (SupplierDebitNote $note) use (&$changes): void {
            $rate = (float) ($note->vat_rate ?? Setting::get('vat_rate', 20));
            $subtotal = 0.0;
            $vat = 0.0;

            foreach ($note->items as $item) {
                if ($item->is_note) {
                    continue;
                }

                $lineValue = Money::roundDown(DocumentTotalsCalculator::lineValue([
                    'quantity' => (float) $item->quantity,
                    'price' => (float) $item->amount,
                    'per' => $item->per,
                ]));

                $line = SupplierInvoiceTotalsCalculator::debitNoteLine(
                    $lineValue,
                    (float) ($item->discount_percent ?? 0),
                    (bool) $item->vat_applicable,
                    true,
                    $rate,
                    $item->net_override !== null ? (float) $item->net_override : null,
                    $item->vat_override !== null ? (float) $item->vat_override : null,
                );

                $item->update(['line_value' => $lineValue, 'total' => $line['net'], 'vat_amount' => $line['vat']]);

                $subtotal += $line['net'];
                $vat += $line['vat'];
            }

            $old = round((float) $note->total, 2);
            $new = round($subtotal + $vat, 2);

            $note->update(['subtotal' => round($subtotal, 2), 'vat_amount' => round($vat, 2), 'total' => $new]);

            if (abs($old - $new) >= 0.005) {
                $changes[] = ['note' => $note->reference, 'old' => $old, 'new' => $new];
            }
        });

        return $changes;
    }

    /**
     * @return array<int, array{invoice: string, note: string, old: float, new: float}>
     */
    private function recalculateDeductions(): array
    {
        $changes = [];

        SupplierInvoice::query()->whereHas('debitNotes')->with(['debitNotes'])->each(function (SupplierInvoice $invoice) use (&$changes): void {
            $before = $invoice->debitNotes->mapWithKeys(fn ($note) => [$note->id => [
                'reference' => $note->reference,
                'applied' => round((float) $note->pivot->applied_amount, 2),
            ]]);

            $invoice->resyncDebitNoteDeductions();

            $after = $invoice->debitNotes()->get()->mapWithKeys(fn ($note) => [$note->id => round((float) $note->pivot->applied_amount, 2)]);

            foreach ($before as $noteId => $row) {
                if (abs($row['applied'] - $after[$noteId]) >= 0.005) {
                    $changes[] = [
                        'invoice' => $invoice->supplier_invoice_no,
                        'note' => $row['reference'],
                        'old' => $row['applied'],
                        'new' => $after[$noteId],
                    ];
                }
            }
        });

        return $changes;
    }
}
