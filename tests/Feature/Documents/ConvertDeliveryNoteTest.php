<?php

use App\Actions\ConvertDeliveryNoteToInvoice;
use App\DocumentStatus;
use App\DocumentType;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushCache();
    Setting::set('inv_prefix', 'INV', 'string');
    Setting::set('number_padding', '4', 'integer');
});

it('converts a delivery note to an invoice with correct doc_number format', function () {
    $dn = Document::factory()->deliveryNote()->has(DocumentItem::factory()->count(2), 'items')->create();

    $action = app(ConvertDeliveryNoteToInvoice::class);
    $invoice = $action->handle($dn);

    expect($invoice->type)->toBe(DocumentType::Invoice)
        ->and($invoice->doc_number)->toMatch('/^INV-\d{4}-\d{4}$/')
        ->and($invoice->status)->toBe(DocumentStatus::Active)
        ->and($invoice->customer_id)->toBe($dn->customer_id)
        ->and($invoice->converted_from_id)->toBe($dn->id);
});

it('marks the source delivery note as converted', function () {
    $dn = Document::factory()->deliveryNote()->has(DocumentItem::factory()->count(1), 'items')->create();

    app(ConvertDeliveryNoteToInvoice::class)->handle($dn);

    expect($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

it('duplicates all line items to the new invoice with details and quantity', function () {
    $dn = Document::factory()->deliveryNote()->has(DocumentItem::factory()->count(3), 'items')->create();

    $invoice = app(ConvertDeliveryNoteToInvoice::class)->handle($dn);

    expect($invoice->items()->count())->toBe(3);

    $dnItems = $dn->items->pluck('details')->sort()->values();
    $invItems = $invoice->items->pluck('details')->sort()->values();
    expect($dnItems)->toEqual($invItems);
});

it('sets price to zero on all invoice line items at conversion', function () {
    $dn = Document::factory()->deliveryNote()
        ->has(DocumentItem::factory()->count(2), 'items')
        ->create();

    $invoice = app(ConvertDeliveryNoteToInvoice::class)->handle($dn);

    foreach ($invoice->items as $item) {
        expect((float) $item->price)->toBe(0.0)
            ->and((float) $item->line_value)->toBe(0.0);
    }
});

it('sets invoice totals to zero at conversion so user fills them in', function () {
    $customer = \App\Models\Customer::factory()->create(['trade_discount' => 0]);

    $dn = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory()->state(['quantity' => 1, 'price' => 0, 'line_value' => 0]), 'items')
        ->create();

    $invoice = app(ConvertDeliveryNoteToInvoice::class)->handle($dn);

    expect((float) $invoice->subtotal)->toBe(0.0)
        ->and((float) $invoice->discount_amount)->toBe(0.0)
        ->and((float) $invoice->vat_amount)->toBe(0.0)
        ->and((float) $invoice->total_value)->toBe(0.0);
});

it('rejects conversion of an already-converted delivery note', function () {
    $dn = Document::factory()->deliveryNote()->has(DocumentItem::factory(), 'items')->create([
        'status' => DocumentStatus::Converted,
    ]);

    expect(fn () => app(ConvertDeliveryNoteToInvoice::class)->handle($dn))
        ->toThrow(DomainException::class, 'already been converted');
});

it('rejects conversion of an invoice document', function () {
    $inv = Document::factory()->invoice()->has(DocumentItem::factory(), 'items')->create();

    expect(fn () => app(ConvertDeliveryNoteToInvoice::class)->handle($inv))
        ->toThrow(DomainException::class, 'Only delivery notes');
});
