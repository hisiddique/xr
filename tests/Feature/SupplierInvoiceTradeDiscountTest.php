<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Models\User;
use App\Services\SupplierInvoiceTotalsCalculator;
use App\SupplierInvoicePaymentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Setting::updateOrCreate(['key' => 'supinv_prefix'], ['key' => 'supinv_prefix', 'value' => 'SUPINV', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'number_padding'], ['key' => 'number_padding', 'value' => '4', 'type' => 'integer']);
    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => '20', 'type' => 'float']);
    Setting::flushCache();
});

// ── Calculator ────────────────────────────────────────────────
test('calculator applies an unchecked discount to the net amount', function () {
    $totals = app(SupplierInvoiceTotalsCalculator::class)
        ->calculate([['line_total' => 1000, 'vat_applicable' => true]], 10, false);

    expect($totals)->toMatchArray([
        'net' => 1000.0,
        'vat' => 200.0,
        'gross' => 1200.0,
        'discount' => 100.0,
        'payable' => 1100.0,
    ]);
});

test('calculator applies a checked discount to the gross amount without touching vat', function () {
    $totals = app(SupplierInvoiceTotalsCalculator::class)
        ->calculate([['line_total' => 1000, 'vat_applicable' => true]], 10, true);

    expect($totals['vat'])->toBe(200.0)
        ->and($totals['discount'])->toBe(120.0)
        ->and($totals['payable'])->toBe(1080.0);
});

test('calculator with a zero percent discount leaves the gross payable', function () {
    $totals = app(SupplierInvoiceTotalsCalculator::class)
        ->calculate([['line_total' => 1000, 'vat_applicable' => true]], 0, false);

    expect($totals['discount'])->toBe(0.0)
        ->and($totals['payable'])->toBe(1200.0);
});

test('calculator taxes only vat_applicable lines and discounts the full net', function () {
    $totals = app(SupplierInvoiceTotalsCalculator::class)->calculate([
        ['line_total' => 1000, 'vat_applicable' => true],
        ['line_total' => 500, 'vat_applicable' => false],
    ], 10, false);

    expect($totals['net'])->toBe(1500.0)
        ->and($totals['vat'])->toBe(200.0)
        ->and($totals['gross'])->toBe(1700.0)
        ->and($totals['discount'])->toBe(150.0)
        ->and($totals['payable'])->toBe(1550.0);
});

// ── Form + model ──────────────────────────────────────────────
test('creating an invoice snapshots the supplier trade discount and stores the discount amount', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 15]);
    $plainSupplier = Supplier::factory()->create(['trade_discount' => 0]);

    $items = [['product_code' => '', 'quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => true]];

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('items', $items)
        ->call('save');

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $plainSupplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('items', $items)
        ->call('save');

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->latest('id')->first()->load('items');
    $plainInvoice = SupplierInvoice::where('supplier_id', $plainSupplier->id)->latest('id')->first()->load('items');

    expect((float) $invoice->trade_discount)->toBe(15.0)
        ->and((float) $invoice->discount_amount)->toBe(150.0)
        ->and($invoice->payableTotal)->toBe(round($invoice->grossTotal - 150.0, 2))
        ->and($invoice->payableTotal)->toBe(1050.0)
        ->and($invoice->vatTotal)->toBe($plainInvoice->vatTotal);
});

test('editing an invoice with discount on gross recomputes the stored discount amount', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 15]);
    $items = [['product_code' => '', 'quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => true]];

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('items', $items)
        ->call('save');

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->latest('id')->first();

    Livewire::test('pages::supplier-invoices.form', ['supplierInvoice' => $invoice])
        ->set('discountOnGross', true)
        ->set('items', $items)
        ->call('save');

    $invoice = $invoice->fresh('items');

    expect((float) $invoice->discount_amount)->toBe(180.0)
        ->and($invoice->discount_on_gross)->toBeTrue()
        ->and($invoice->payableTotal)->toBe(1020.0)
        ->and($invoice->vatTotal)->toBe(200.0);
});

test('a saved invoice discount is independent of later supplier trade discount changes', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 15]);
    $items = [['product_code' => '', 'quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => true]];

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('items', $items)
        ->call('save');

    $supplier->update(['trade_discount' => 40]);

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->latest('id')->first()->load('items');

    expect((float) $invoice->trade_discount)->toBe(15.0)
        ->and((float) $invoice->discount_amount)->toBe(150.0)
        ->and($invoice->payableTotal)->toBe(1050.0);
});

test('outstanding amount and payment status are measured against the payable total', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => true, 'line_total' => 1000]), 'items')
        ->create([
            'created_by' => $this->user->id,
            'trade_discount' => 10,
            'discount_amount' => 100,
            'discount_on_gross' => false,
        ]);

    $payout = SupplierPayout::create([
        'supplier_id' => $invoice->supplier_id,
        'amount' => 600,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $invoice->id,
        'allocated_amount' => 600,
        'deduction_amount' => 0,
    ]);

    $invoice = $invoice->fresh(['items', 'payoutAllocations', 'debitNotes']);

    expect($invoice->grossTotal)->toBe(1200.0)
        ->and($invoice->payableTotal)->toBe(1100.0)
        ->and($invoice->outstandingAmount)->toBe(500.0)
        ->and($invoice->paymentStatus())->toBe(SupplierInvoicePaymentStatus::Partial);
});

test('a zero-discount invoice keeps payable equal to gross', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => true, 'line_total' => 1000]), 'items')
        ->create([
            'created_by' => $this->user->id,
            'trade_discount' => 0,
            'discount_amount' => 0,
            'discount_on_gross' => false,
        ]);

    $invoice = $invoice->fresh(['items', 'payoutAllocations', 'debitNotes']);

    expect($invoice->discountTotal)->toBe(0.0)
        ->and($invoice->payableTotal)->toBe($invoice->grossTotal)
        ->and($invoice->payableTotal)->toBe(1200.0)
        ->and($invoice->outstandingAmount)->toBe(1200.0)
        ->and($invoice->paymentStatus())->toBe(SupplierInvoicePaymentStatus::Unpaid);
});
