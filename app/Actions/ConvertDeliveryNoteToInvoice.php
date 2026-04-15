<?php

namespace App\Actions;

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Document;
use App\Services\DocumentNumberGenerator;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class ConvertDeliveryNoteToInvoice
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
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

            $deliveryNote->loadMissing('items');

            /** @var Document $invoice */
            $invoice = Document::create([
                'customer_id' => $deliveryNote->customer_id,
                'type' => DocumentType::Invoice,
                'doc_number' => $docNumber,
                'doc_date' => now()->toDateString(),
                'subtotal' => 0,
                'trade_discount' => 0,
                'discount_amount' => 0,
                'vat_amount' => 0,
                'total_value' => 0,
                'status' => DocumentStatus::Active,
                'created_by' => Auth::id() ?? $deliveryNote->created_by,
                'converted_from_id' => $deliveryNote->id,
            ]);

            foreach ($deliveryNote->items as $item) {
                $invoice->items()->create([
                    'details' => $item->details,
                    'quantity' => $item->quantity,
                    'price' => 0,
                    'per' => $item->per,
                    'line_value' => 0,
                ]);
            }

            $deliveryNote->update(['status' => DocumentStatus::Converted]);

            return $invoice;
        });
    }
}
