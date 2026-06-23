<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\DocumentTotalsCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('credit-notes:migrate-discount')]
#[Description('Backfills discount_percent on credit note items and recalculates totals.')]
class MigrateCreditNoteDiscount extends Command
{
    public function handle(): int
    {
        $creditNotes = Document::creditNotes()->with(['items', 'customer'])->get();

        foreach ($creditNotes as $creditNote) {
            foreach ($creditNote->items as $item) {
                if ($item->is_note) {
                    continue;
                }

                $lineValue = (float) $item->line_value;
                $netValue = (float) $item->net_value;

                if ($lineValue > 0 && (float) $item->discount_percent == 0) {
                    $item->discount_percent = round(($netValue / $lineValue) * 100, 2);
                    $item->save();
                } elseif ($lineValue == 0 && $netValue > 0) {
                    $item->discount_percent = 100;
                    $item->save();
                }
            }

            $itemsArray = $creditNote->items->map(fn ($item) => [
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
                'per' => $item->per,
                'discount_percent' => (float) $item->discount_percent,
                'is_note' => (bool) $item->is_note,
            ])->values();

            $totals = DocumentTotalsCalculator::creditNoteTotal(
                collect($itemsArray),
                $creditNote->customer
            );

            $creditNote->subtotal = $totals['subtotal'];
            $creditNote->vat_amount = $totals['vat'];
            $creditNote->total_value = $totals['total'];
            $creditNote->save();

            $this->info("Processed CN #{$creditNote->doc_number}");
        }

        return self::SUCCESS;
    }
}
