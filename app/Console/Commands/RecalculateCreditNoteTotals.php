<?php

namespace App\Console\Commands;

use App\DocumentType;
use App\Models\Document;
use App\Models\Setting;
use App\Services\DocumentTotalsCalculator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('credit-notes:recalculate-totals')]
#[Description('Recalculate net_value, subtotal, vat_amount, total_value for all credit notes using the new discount semantics')]
class RecalculateCreditNoteTotals extends Command
{
    public function handle(): int
    {
        $creditNotes = Document::where('type', DocumentType::CreditNote)
            ->with(['items', 'customer'])
            ->get();

        $vatRate = (float) Setting::get('vat_rate', 20);
        $count = 0;

        foreach ($creditNotes as $document) {
            foreach ($document->items as $item) {
                if ($item->is_note) {
                    continue;
                }

                $lineVal = round(DocumentTotalsCalculator::lineValue($item->toArray()), 2);
                $discountPercent = (float) ($item->discount_percent ?? 0);
                $netValue = $discountPercent > 0
                    ? round($lineVal * (1 - $discountPercent / 100), 2)
                    : $lineVal;

                $item->update(['net_value' => $netValue]);
            }

            $subtotal = round($document->items()->where('is_note', false)->sum('net_value'), 2);
            $vatAmount = $document->customer->vat_registered
                ? round($subtotal * ($vatRate / 100), 2)
                : 0.0;

            $document->update([
                'subtotal' => $subtotal,
                'vat_amount' => $vatAmount,
                'total_value' => round($subtotal + $vatAmount, 2),
            ]);

            $this->info("Processed credit note: {$document->doc_number}");
            $count++;
        }

        $this->info("Done. {$count} credit note(s) recalculated.");

        return self::SUCCESS;
    }
}
