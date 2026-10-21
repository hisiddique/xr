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

class CloneInvoice
{
    public function __construct(
        private DocumentNumberGenerator $numberGenerator,
        private DocumentTotalsCalculator $totalsCalculator,
    ) {}

    /**
     * Create a paired delivery note and invoice from the submitted clone form.
     *
     * @param  array{doc_date: string, order_no?: string|null, assigned_to?: int|string|null}  $attributes
     * @param  array<int, array{details?: string|null, is_note?: bool, quantity?: float|string, price?: float|string, per?: string|null}>  $items
     *
     * @throws DomainException
     */
    public function handle(Document $source, array $attributes, array $items): Document
    {
        if ($source->type !== DocumentType::Invoice) {
            throw new DomainException('Only invoices can be cloned.');
        }

        return DB::transaction(function () use ($source, $attributes, $items) {
            $createdBy = Auth::id() ?? $source->created_by;

            $totals = $this->totalsCalculator->calculate(
                collect($items),
                $source->customer,
                $source->vat_rate !== null ? (float) $source->vat_rate : null,
            );

            $shared = [
                'customer_id' => $source->customer_id,
                'doc_date' => $attributes['doc_date'],
                'order_no' => ($attributes['order_no'] ?? null) ?: null,
                'show_pricing' => true,
                'created_by' => $createdBy,
                'assigned_to' => $attributes['assigned_to'] ?? null,
                'subtotal' => $totals['subtotal'],
                'trade_discount' => $totals['discount'],
                'discount_amount' => $totals['discount_amount'],
                'vat_amount' => $totals['vat'],
                'vat_rate' => $totals['vat_rate'],
                'total_value' => $totals['total'],
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
                ...$shared,
                'type' => DocumentType::DeliveryNote,
                'doc_number' => $this->numberGenerator->numberForConversion('DN', $sequence),
                'status' => DocumentStatus::Converted,
            ]);
            $this->createItems($deliveryNote, $items);

            /** @var Document $invoice */
            $invoice = Document::create([
                ...$shared,
                'type' => DocumentType::Invoice,
                'doc_number' => $this->numberGenerator->numberForConversion('INV', $sequence),
                'status' => DocumentStatus::Active,
                'converted_from_id' => $deliveryNote->id,
            ]);
            $this->createItems($invoice, $items);

            return $invoice;
        });
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function createItems(Document $target, array $items): void
    {
        foreach ($items as $item) {
            $isNote = ! empty($item['is_note']);
            $quantity = $isNote ? 0 : (float) ($item['quantity'] ?? 0);
            $price = $isNote ? 0 : (float) ($item['price'] ?? 0);
            $per = $isNote ? null : (($item['per'] ?? null) ?: null);

            $target->items()->create([
                'details' => $item['details'] ?? null,
                'is_note' => $isNote,
                'quantity' => $quantity,
                'price' => $price,
                'per' => $per,
                'line_value' => $isNote ? 0 : round(DocumentTotalsCalculator::lineValue([
                    'quantity' => $quantity,
                    'price' => $price,
                    'per' => $per,
                ]), 2),
            ]);
        }
    }
}
