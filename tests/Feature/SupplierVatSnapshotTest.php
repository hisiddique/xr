<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteItem;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());

    foreach ([
        'supinv_prefix' => ['SUPINV', 'string'],
        'supdn_prefix' => ['SUPDN', 'string'],
        'number_padding' => ['4', 'integer'],
        'vat_rate' => ['20', 'float'],
    ] as $key => [$value, $type]) {
        Setting::updateOrCreate(['key' => $key], ['key' => $key, 'value' => $value, 'type' => $type]);
    }
    Setting::flushCache();
});

function changeVatRateTo(string $rate): void
{
    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => $rate, 'type' => 'float']);
    Setting::flushCache();
}

test('a saved supplier invoice keeps its vat and rate when the settings rate changes', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 0]);

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('items', [['product_code' => '', 'quantity' => 1, 'unit_amount' => 10.03, 'vat_applicable' => true, 'gross_override' => null]])
        ->call('save');

    changeVatRateTo('25');

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->firstOrFail()->load('items');

    expect((float) $invoice->vat_rate)->toBe(20.0)
        ->and((float) $invoice->items->first()->vat_amount)->toBe(2.0)
        ->and($invoice->vatTotal)->toBe(2.0)
        ->and($invoice->payableTotal)->toBe(12.03);
});

test('editing a supplier invoice reuses its stored rate instead of the current setting', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 0]);
    $items = [['product_code' => '', 'quantity' => 1, 'unit_amount' => 100, 'vat_applicable' => true, 'gross_override' => null]];

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('items', $items)
        ->call('save');

    changeVatRateTo('25');

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->firstOrFail();

    Livewire::test('pages::supplier-invoices.form', ['supplierInvoice' => $invoice])
        ->set('items', $items)
        ->call('save');

    $invoice->refresh()->load('items');

    expect((float) $invoice->vat_rate)->toBe(20.0)
        ->and($invoice->vatTotal)->toBe(20.0);
});

test('a saved debit note keeps its vat rate and line vat when the settings rate changes', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [[
            'id' => null, 'details' => 'Damaged goods', 'quantity' => '1', 'price' => '4.99', 'per' => 'Each',
            'is_note' => false, 'discount_percent' => null, 'vat_applicable' => true,
        ]])
        ->call('commitDebitNote');

    changeVatRateTo('25');

    $note = SupplierDebitNote::firstWhere('supplier_id', $supplier->id)->load('items');

    expect((float) $note->vat_rate)->toBe(20.0)
        ->and((float) $note->items->first()->vat_amount)->toBe(0.99)
        ->and($note->effectiveVatRate())->toBe(20.0);
});

test('the backfill command snapshots invoices and splits debit note vat exactly', function () {
    $supplier = Supplier::factory()->create();

    $invoice = SupplierInvoice::factory()->create(['supplier_id' => $supplier->id, 'vat_rate' => null]);
    $invoice->items()->create(['quantity' => 1, 'unit_amount' => 10.03, 'vat_applicable' => true, 'line_total' => 10.03, 'sort_order' => 0]);

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 10.00,
        'vat_amount' => 2.00,
        'total' => 12.00,
        'created_by' => auth()->id(),
    ]);
    foreach ([[3.33, 0], [3.33, 1], [3.34, 2]] as [$total, $sort]) {
        SupplierDebitNoteItem::create([
            'supplier_debit_note_id' => $note->id, 'description' => 'Line '.$sort, 'quantity' => 1,
            'amount' => $total, 'line_value' => $total, 'total' => $total, 'vat_applicable' => true, 'sort_order' => $sort,
        ]);
    }

    $this->artisan('supplier-vat:backfill-snapshots')->expectsConfirmation('Apply these changes?', 'yes')->assertSuccessful();

    $invoice->refresh()->load('items');
    $note->refresh()->load('items');

    expect((float) $invoice->vat_rate)->toBe(20.0)
        ->and((float) $invoice->items->first()->vat_amount)->toBe(2.01)
        ->and((float) $note->vat_rate)->toBe(20.0)
        ->and(round((float) $note->items->sum('vat_amount'), 2))->toBe(2.0)
        ->and((float) $note->total)->toBe(12.0);
});
