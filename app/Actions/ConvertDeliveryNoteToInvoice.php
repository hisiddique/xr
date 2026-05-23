<?php

namespace App\Actions;

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Document;
use App\Services\DocumentNumberGenerator;
use App\Services\DocumentTotalsCalculator;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConvertDeliveryNoteToInvoice
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
        private DocumentTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * Convert a delivery note to an invoice.
     *
     * @throws DomainException
     */
    public function handle(Document $deliveryNote): Document
    {
        if ($deliveryNote->type !== DocumentType::DeliveryNote) {
            throw new DomainException('Only delivery notes can be converted to invoices.');
        }

        if ($deliveryNote->status === DocumentStatus::Converted) {
            throw new DomainException("Delivery note {$deliveryNote->doc_number} has already been converted.");
        }

        return DB::transaction(function () use ($deliveryNote) {
            $docNumber = $this->numberGenerator->nextFor('INV');

            $deliveryNote->loadMissing(['items', 'customer']);

            $itemsForCalc = $deliveryNote->items->map(fn ($item) => [
                'is_note' => (bool) $item->is_note,
                'quantity' => (float) $item->quantity,
                'price' => (float) $item->price,
            ]);
            $totals = $this->totalsCalculator->calculate($itemsForCalc, $deliveryNote->customer);

            /** @var Document $invoice */
            $invoice = Document::create([
                'customer_id' => $deliveryNote->customer_id,
                'type' => DocumentType::Invoice,
                'doc_number' => $docNumber,
                'doc_date' => now()->toDateString(),
                'due_by' => $deliveryNote->due_by,
                'order_no' => $deliveryNote->order_no,
                'subtotal' => $totals['subtotal'],
                'trade_discount' => $totals['discount'],
                'discount_amount' => $totals['discount_amount'],
                'vat_amount' => $totals['vat'],
                'total_value' => $totals['total'],
                'show_pricing' => true,
                'status' => DocumentStatus::Active,
                'created_by' => Auth::id() ?? $deliveryNote->created_by,
                'converted_from_id' => $deliveryNote->id,
            ]);

            foreach ($deliveryNote->items as $item) {
                $isNote = (bool) $item->is_note;
                $qty = $isNote ? 0 : (float) $item->quantity;
                $price = $isNote ? 0 : (float) $item->price;
                $per = $item->per;

                $invoice->items()->create([
                    'details' => $item->details,
                    'is_note' => $isNote,
                    'quantity' => $qty,
                    'price' => $price,
                    'per' => $per,
                    'line_value' => $isNote ? 0 : round(DocumentTotalsCalculator::lineValue(['quantity' => $qty, 'price' => $price, 'per' => $per]), 2),
                ]);
            }

            $deliveryNote->update(['status' => DocumentStatus::Converted]);

            return $invoice;
        });
    }
}
