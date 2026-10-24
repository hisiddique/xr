<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteItem;
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

test('calculator rounds the trade discount down to the penny', function (float $net, float $discount, float $payable) {
    $totals = app(SupplierInvoiceTotalsCalculator::class)
        ->calculate([['line_total' => $net, 'vat_applicable' => false]], 2, false);

    expect($totals['discount'])->toBe($discount)
        ->and($totals['payable'])->toBe($payable);
})->with([
    'below half a penny' => [27.20, 0.54, 26.66],
    'exactly half a penny' => [27.25, 0.54, 26.71],
    'above half a penny' => [27.29, 0.54, 26.75],
]);

test('a vat-inclusive amount is split into net and vat rounded to the penny', function () {
    expect(SupplierInvoiceTotalsCalculator::splitInclusive(10.00, true, 20))->toBe(['net' => 8.33, 'vat' => 1.67])
        ->and(SupplierInvoiceTotalsCalculator::splitInclusive(10.00, false, 20))->toBe(['net' => 10.0, 'vat' => 0.0]);
});

test('a debit note deduction uses stored vat so inclusive lines do not drift by a penny', function () {
    $calculator = app(SupplierInvoiceTotalsCalculator::class);
    $lines = [['line_total' => 8.33, 'vat_applicable' => true, 'vat_amount' => 1.67]];
    $note = new SupplierDebitNote(['total' => 1.20]);
    $note->id = 1;
    $note->setRelation('items', collect([new SupplierDebitNoteItem(['total' => 1.00, 'vat_applicable' => true, 'vat_amount' => 0.20])]));

    $deductions = $calculator->debitNoteDeductions($lines, 2.5, false, [$note], 20);

    expect($deductions[1])->toBe(1.18);
});

test('a debit note deducts its net less its discount share plus its VAT, never more than its total', function () {
    $calculator = app(SupplierInvoiceTotalsCalculator::class);
    $lines = [['line_total' => 34.20, 'vat_applicable' => true]];
    $note = new SupplierDebitNote(['total' => 8.40]);
    $note->id = 1;
    $note->setRelation('items', collect([new SupplierDebitNoteItem(['total' => 7.00, 'vat_applicable' => true])]));

    $payable = $calculator->calculate($lines, 2, false)['payable'];
    $deductions = $calculator->debitNoteDeductions($lines, 2, false, [$note]);

    expect($deductions[1])->toBe(8.26)
        ->and($deductions[1])->toBeLessThanOrEqual(8.40)
        ->and(round($payable - $deductions[1], 2))->toBe(32.10);
});

test('line vat is rounded down to the penny', function () {
    $totals = app(SupplierInvoiceTotalsCalculator::class)
        ->calculate([['line_total' => 10.03, 'vat_applicable' => true]], 0, false);

    expect($totals['vat'])->toBe(2.0)
        ->and($totals['gross'])->toBe(12.03);
});

test('a manual line gross override replaces the calculated vat on that line only', function () {
    $totals = app(SupplierInvoiceTotalsCalculator::class)->calculate([
        ['line_total' => 10.03, 'vat_applicable' => true, 'gross_override' => 12.05],
        ['line_total' => 10.00, 'vat_applicable' => true, 'gross_override' => null],
    ], 0, false);

    expect($totals['vat'])->toBe(4.02)
        ->and($totals['gross'])->toBe(24.05);
});

test('an invoice item with a gross override reports its own vat and gross', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()->create(['supplier_id' => $supplier->id]);
    $invoice->items()->create(['quantity' => 1, 'unit_amount' => 10.03, 'vat_applicable' => true, 'line_total' => 10.03, 'gross_override' => 12.05, 'sort_order' => 0]);
    $invoice->load('items');

    expect($invoice->items->first()->line_gross)->toBe(12.05)
        ->and($invoice->vatTotal)->toBe(2.02)
        ->and($invoice->grossTotal)->toBe(12.05);
});

test('a debit note keeps its own total when the invoice has no trade discount', function () {
    $note = new SupplierDebitNote(['total' => 8.40]);
    $note->id = 1;

    $deductions = app(SupplierInvoiceTotalsCalculator::class)
        ->debitNoteDeductions([['line_total' => 34.20, 'vat_applicable' => true]], 0, false, [$note]);

    expect($deductions[1])->toBe(8.40);
});

test('the deductions backfill command corrects a stale applied amount and leaves correct ones alone', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 20, 'vat_applicable' => true, 'line_total' => 20]), 'items')
        ->create(['created_by' => $this->user->id, 'trade_discount' => 10, 'discount_on_gross' => false]);

    $note = SupplierDebitNote::create([
        'supplier_id' => $invoice->supplier_id,
        'supplier_invoice_id' => $invoice->id,
        'doc_date' => now(),
        'subtotal' => 1,
        'vat_amount' => 0.20,
        'total' => 1.20,
        'created_by' => $this->user->id,
    ]);
    $note->items()->create(['description' => 'Returned', 'quantity' => 1, 'amount' => 1, 'total' => 1, 'vat_applicable' => true]);
    $note->appliedInvoices()->attach($invoice->id, ['applied_amount' => 1.49, 'applied_at' => now()]);

    $this->artisan('supplier-debit-notes:backfill-deductions', ['--dry-run' => true])->assertSuccessful();
    expect((float) $note->appliedInvoices()->first()->pivot->applied_amount)->toBe(1.49);

    $this->artisan('supplier-debit-notes:backfill-deductions')->expectsConfirmation('Apply these changes?', 'yes')->assertSuccessful();
    expect((float) $note->appliedInvoices()->first()->pivot->applied_amount)->toBe(1.10);

    $this->artisan('supplier-debit-notes:backfill-deductions')->expectsOutputToContain('Nothing to backfill')->assertSuccessful();
});
