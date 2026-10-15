<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('supplier-vat:backfill-snapshots {--dry-run : Preview the changes without writing them}')]
#[Description('Snapshots the current VAT rate onto existing supplier invoices and debit notes, and per-line VAT amounts onto their items, without changing any stored note totals or applied amounts.')]
class BackfillSupplierVatSnapshots extends Command
{
    public function handle(): int
    {
        $rate = (float) Setting::get('vat_rate', 20);

        $previewRows = [];
        $invoiceCount = 0;
        $itemCount = 0;

        SupplierInvoice::whereNull('vat_rate')
            ->with('items')
            ->chunkById(200, function ($invoices) use ($rate, &$previewRows, &$invoiceCount, &$itemCount) {
                foreach ($invoices as $invoice) {
                    $invoiceCount++;
                    $vat = 0.0;

                    foreach ($invoice->items as $item) {
                        if ($item->vat_amount === null) {
                            $itemCount++;
                        }

                        $vat += $this->snapshotFor($item, $rate);
                    }

                    $discount = (float) $invoice->discount_amount > 0
                        ? (float) $invoice->discount_amount
                        : $invoice->discountTotal;
                    $newPayable = max(0, round($invoice->netTotal + $vat - $discount, 2));
                    $currentPayable = $invoice->payableTotal;

                    if (abs($newPayable - $currentPayable) >= 0.005) {
                        $previewRows[] = [
                            'reference' => $invoice->supplier_invoice_no,
                            'current' => number_format($currentPayable, 2),
                            'new' => number_format($newPayable, 2),
                        ];
                    }
                }
            });

        $noteCount = SupplierDebitNote::whereNull('vat_rate')->count();

        if ($invoiceCount === 0 && $noteCount === 0) {
            $this->info('Nothing to backfill — VAT snapshots are already in place.');

            return self::SUCCESS;
        }

        if ($previewRows !== []) {
            $this->table(['Reference', 'Current Payable', 'New Payable'], $previewRows);
        }

        $this->info("{$invoiceCount} invoice(s), {$itemCount} invoice item(s) and {$noteCount} debit note(s) will be snapshotted at a VAT rate of {$rate}%.");
        $this->info(count($previewRows).' invoice(s) will show a different payable total.');

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        SupplierInvoice::whereNull('vat_rate')
            ->with('items')
            ->chunkById(200, function ($invoices) use ($rate) {
                DB::transaction(function () use ($invoices, $rate) {
                    foreach ($invoices as $invoice) {
                        foreach ($invoice->items as $item) {
                            if ($item->vat_amount === null) {
                                $item->update(['vat_amount' => $this->snapshotFor($item, $rate)]);
                            }
                        }

                        $invoice->update(['vat_rate' => $rate]);
                    }
                });
            });

        SupplierDebitNote::whereNull('vat_rate')
            ->with('items')
            ->chunkById(200, function ($notes) use ($rate) {
                DB::transaction(function () use ($notes, $rate) {
                    foreach ($notes as $note) {
                        $shares = $this->splitNoteVat($note);

                        foreach ($note->items as $item) {
                            $item->update(['vat_amount' => $shares[$item->id] ?? 0]);
                        }

                        $note->update(['vat_rate' => $rate]);
                    }
                });
            });

        $this->info("Backfill complete. {$invoiceCount} invoice(s), {$itemCount} invoice item(s) and {$noteCount} debit note(s) updated.");

        return self::SUCCESS;
    }

    private function snapshotFor(SupplierInvoiceItem $item, float $rate): float
    {
        if ($item->vat_amount !== null) {
            return (float) $item->vat_amount;
        }

        if (! $item->vat_applicable) {
            return 0.0;
        }

        if ($item->gross_override !== null) {
            return round((float) $item->gross_override - (float) $item->line_total, 2);
        }

        return round((float) $item->line_total * $rate / 100, 2);
    }

    /**
     * @return array<int, float>
     */
    private function splitNoteVat(SupplierDebitNote $note): array
    {
        $applicable = $note->items->where('vat_applicable', true)->values();
        $vatCents = (int) round((float) $note->vat_amount * 100);
        $totalCents = (int) $applicable->sum(fn ($item) => (int) round((float) $item->total * 100));

        $shares = [];

        foreach ($note->items as $item) {
            $shares[$item->id] = 0.0;
        }

        if ($applicable->isEmpty() || $totalCents <= 0) {
            return $shares;
        }

        $allocated = 0;
        $lastIndex = $applicable->count() - 1;

        foreach ($applicable as $index => $item) {
            if ($index === $lastIndex) {
                $shareCents = $vatCents - $allocated;
            } else {
                $shareCents = intdiv($vatCents * (int) round((float) $item->total * 100), $totalCents);
                $allocated += $shareCents;
            }

            $shares[$item->id] = $shareCents / 100;
        }

        return $shares;
    }
}
