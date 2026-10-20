<?php

namespace App\Actions;

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Document;
use App\Services\DocumentNumberGenerator;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CloneInvoice
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
    ) {}

    /**
     * Clone an invoice together with a fresh paired delivery note.
     *
     * @throws DomainException
     */
    public function handle(Document $source): Document
    {
        if ($source->type !== DocumentType::Invoice) {
            throw new DomainException('Only invoices can be cloned.');
        }

        return DB::transaction(function () use ($source) {
            $source->loadMissing('items');

            $createdBy = Auth::id() ?? $source->created_by;

            $money = [
                'subtotal' => $source->subtotal,
                'trade_discount' => $source->trade_discount,
                'discount_amount' => $source->discount_amount,
                'vat_amount' => $source->vat_amount,
                'vat_rate' => $source->vat_rate,
                'total_value' => $source->total_value,
            ];

            $sequence = max(
                $this->numberGenerator->extractSequence($this->numberGenerator->nextFor('DN')),
                $this->numberGenerator->extractSequence($this->numberGenerator->nextFor('INV')),
            );

            while (Document::withTrashed()->whereIn('doc_number', [
                $this->numberGenerator->numberForConversion('DN', $sequence),
                $this->numberGenerator->numberForConversion('INV', $sequence),
            ])->exists()) {
                $sequence++;
            }

            /** @var Document $deliveryNote */
            $deliveryNote = Document::create([
                'customer_id' => $source->customer_id,
                'type' => DocumentType::DeliveryNote,
                'doc_number' => $this->numberGenerator->numberForConversion('DN', $sequence),
                'doc_date' => now()->toDateString(),
                'order_no' => $source->order_no,
                'show_pricing' => true,
                'status' => DocumentStatus::Converted,
                'created_by' => $createdBy,
                'assigned_to' => $source->assigned_to,
                ...$money,
            ]);
            $this->copyItems($source, $deliveryNote);

            /** @var Document $invoice */
            $invoice = Document::create([
                'customer_id' => $source->customer_id,
                'type' => DocumentType::Invoice,
                'doc_number' => $this->numberGenerator->numberForConversion('INV', $sequence),
                'doc_date' => now()->toDateString(),
                'order_no' => $source->order_no,
                'show_pricing' => true,
                'status' => DocumentStatus::Active,
                'created_by' => $createdBy,
                'assigned_to' => $source->assigned_to,
                'converted_from_id' => $deliveryNote->id,
                ...$money,
            ]);
            $this->copyItems($source, $invoice);

            return $invoice;
        });
    }

    private function copyItems(Document $source, Document $target): void
    {
        foreach ($source->items as $item) {
            $target->items()->create([
                'details' => $item->details,
                'is_note' => $item->is_note,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'per' => $item->per,
                'line_value' => $item->line_value,
                'discount_percent' => $item->discount_percent,
                'net_value' => $item->net_value,
            ]);
        }
    }
}
