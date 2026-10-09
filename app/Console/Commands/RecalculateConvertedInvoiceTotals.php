<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Document;
use App\Services\DocumentTotalsCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('invoices:recalculate-converted-totals {--dry-run : Preview the changes without writing them}')]
#[Description('Recalculates subtotal, discount, VAT and total on invoices converted from delivery notes before the conversion honoured the item "per" unit. Only invoices whose stored subtotal differs from their line items are touched; each keeps its own trade discount, and VAT is applied only if it was already charged.')]
class RecalculateConvertedInvoiceTotals extends Command
{
    public function handle(DocumentTotalsCalculator $calculator): int
    {
        $changes = Document::invoices()
            ->whereNotNull('converted_from_id')
            ->with('items')
            ->get()
            ->map(function (Document $invoice) use ($calculator) {
                $customer = (new Customer)->forceFill([
                    'trade_discount' => $invoice->trade_discount,
                    'vat_registered' => (float) $invoice->vat_amount > 0,
                ]);

                $totals = $calculator->calculate($invoice->items->map(fn ($item) => [
                    'is_note' => (bool) $item->is_note,
                    'quantity' => $item->quantity,
                    'price' => $item->price,
                    'per' => $item->per,
                ]), $customer);

                return ['invoice' => $invoice, 'totals' => $totals];
            })
            ->filter(fn (array $row) => abs($row['totals']['subtotal'] - (float) $row['invoice']->subtotal) > 0.005)
            ->values();

        if ($changes->isEmpty()) {
            $this->info('No converted invoices need recalculating.');

            return self::SUCCESS;
        }

        $this->table(
            ['Invoice', 'Old Subtotal', 'New Subtotal', 'Old Total', 'New Total'],
            $changes->map(fn (array $row) => [
                $row['invoice']->doc_number,
                number_format((float) $row['invoice']->subtotal, 2),
                number_format($row['totals']['subtotal'], 2),
                number_format((float) $row['invoice']->total_value, 2),
                number_format($row['totals']['total'], 2),
            ]),
        );

        if ($this->option('dry-run')) {
            $this->info("Dry run: {$changes->count()} invoice(s) would be updated.");

            return self::SUCCESS;
        }

        if (! $this->confirm("Update {$changes->count()} invoice(s)?")) {
            return self::FAILURE;
        }

        foreach ($changes as $row) {
            $row['invoice']->update([
                'subtotal' => $row['totals']['subtotal'],
                'discount_amount' => $row['totals']['discount_amount'],
                'vat_amount' => $row['totals']['vat'],
                'total_value' => $row['totals']['total'],
            ]);
        }

        $this->info("Updated {$changes->count()} invoice(s).");

        return self::SUCCESS;
    }
}
