<?php

namespace App\Actions;

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Document;
use App\Services\DocumentNumberGenerator;
use DomainException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CloneDeliveryNote
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
    ) {}

    /**
     * Duplicate a delivery note into a new active delivery note.
     *
     * @throws DomainException
     */
    public function handle(Document $source): Document
    {
        if ($source->type !== DocumentType::DeliveryNote) {
            throw new DomainException('Only delivery notes can be cloned.');
        }

        return DB::transaction(function () use ($source) {
            $source->loadMissing('items');

            /** @var Document $clone */
            $clone = Document::create([
                'customer_id' => $source->customer_id,
                'type' => DocumentType::DeliveryNote,
                'doc_number' => $this->numberGenerator->nextFor('DN'),
                'doc_date' => now()->toDateString(),
                'order_no' => $source->order_no,
                'subtotal' => $source->subtotal,
                'trade_discount' => $source->trade_discount,
                'discount_amount' => $source->discount_amount,
                'vat_amount' => $source->vat_amount,
                'vat_rate' => $source->vat_rate,
                'total_value' => $source->total_value,
                'show_pricing' => $source->show_pricing,
                'status' => DocumentStatus::Active,
                'created_by' => Auth::id() ?? $source->created_by,
                'assigned_to' => $source->assigned_to,
            ]);

            foreach ($source->items as $item) {
                $clone->items()->create([
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

            return $clone;
        });
    }
}
