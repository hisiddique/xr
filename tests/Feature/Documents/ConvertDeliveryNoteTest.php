<?php

use App\Actions\ConvertDeliveryNoteToInvoice;
use App\DocumentStatus;
use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Sequence;
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
        ->and($invoice->doc_number)->toMatch('/^INV-\d{4}$/')
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

it('copies prices and line values from the delivery note to the invoice', function () {
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    $dn = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(
            DocumentItem::factory()->count(2)->state(new Sequence(
                ['quantity' => 2, 'price' => 10, 'line_value' => 20],
                ['quantity' => 1, 'price' => 5, 'line_value' => 5],
            )),
            'items',
        )
        ->create(['show_pricing' => true]);

    $invoice = app(ConvertDeliveryNoteToInvoice::class)->handle($dn);

    $prices = $invoice->items->pluck('price')->map(fn ($p) => (float) $p)->sort()->values()->all();
    expect($prices)->toBe([5.0, 10.0])
        ->and($invoice->items->sum(fn ($i) => (float) $i->line_value))->toBe(25.0);
});

it('recomputes invoice totals from copied line items at conversion', function () {
    Setting::set('vat_rate', '20', 'integer');
    Setting::flushCache();

    $customer = Customer::factory()->create(['trade_discount' => 0, 'vat_registered' => true]);

    $dn = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory()->state(['quantity' => 1, 'price' => 100, 'line_value' => 100]), 'items')
        ->create(['show_pricing' => true]);

    $invoice = app(ConvertDeliveryNoteToInvoice::class)->handle($dn);

    expect((float) $invoice->subtotal)->toBe(100.0)
        ->and((float) $invoice->vat_amount)->toBe(20.0)
        ->and((float) $invoice->total_value)->toBe(120.0);
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
