<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => '20', 'type' => 'float']);
    Setting::flushCache();
});

test('a new debit note extracts vat from the entered amount and is flagged inclusive', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', '2026-01-15')
        ->set('items', [['details' => 'Returned', 'quantity' => 1, 'price' => 10, 'per' => 'Lot', 'discount_percent' => 0, 'vat_applicable' => true]])
        ->call('commitDebitNote')->assertHasNoErrors();

    $note = SupplierDebitNote::with('items')->firstOrFail();

    expect($note->vat_inclusive)->toBeTrue()
        ->and((float) $note->items->first()->total)->toBe(8.33)
        ->and((float) $note->items->first()->vat_amount)->toBe(1.67)
        ->and((float) $note->total)->toBe(10.0);
});

test('editing an applied debit note rebuilds its lines and resyncs the invoice deduction', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 83.33, 'vat_applicable' => true, 'line_total' => 83.33, 'vat_amount' => 16.67]), 'items')
        ->create(['supplier_id' => $supplier->id, 'created_by' => $this->user->id, 'trade_discount' => 0]);

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', '2026-01-15')
        ->set('supplier_invoice_id', $invoice->id)
        ->set('items', [['details' => 'Returned', 'quantity' => 1, 'price' => 10, 'per' => 'Lot', 'discount_percent' => 0, 'vat_applicable' => true]])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::firstOrFail();
    expect((float) $note->appliedInvoices()->first()->pivot->applied_amount)->toBe(10.0);

    Livewire::test('pages::supplier-debit-notes.edit', ['debitNote' => $note])
        ->set('items', [['details' => 'Returned', 'quantity' => 1, 'price' => 6, 'per' => 'Lot', 'discount_percent' => 0, 'vat_applicable' => true]])
        ->call('save')
        ->assertHasNoErrors();

    $note->refresh();
    expect((float) $note->total)->toBe(6.0)
        ->and((float) $note->appliedInvoices()->first()->pivot->applied_amount)->toBe(6.0);
});

test('debit note create prefills the line discount from the selected supplier trade discount', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 2.5]);
    $plain = Supplier::factory()->create(['trade_discount' => 0]);

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->assertSet('tradeDiscount', 2.5)
        ->assertDispatched('debit-note-discount-default', percent: 2.5)
        ->set('supplier_id', $plain->id)
        ->assertSet('tradeDiscount', null)
        ->assertDispatched('debit-note-discount-default', percent: null);
});

test('debit note create opened for a supplier starts with that supplier trade discount on the first line', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 10]);

    Livewire::withQueryParams(['supplier_id' => $supplier->id])
        ->test('pages::supplier-debit-notes.create')
        ->assertSet('items.0.discount_percent', 10.0);
});

test('net and vat overrides on a debit note line replace the calculated figures and are kept on edit', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', '2026-01-15')
        ->set('items', [['details' => 'Returned', 'quantity' => 1, 'price' => 10, 'per' => 'Lot', 'discount_percent' => 0, 'vat_applicable' => true, 'net_override' => 8.30, 'vat_override' => 1.70]])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::with('items')->firstOrFail();
    $item = $note->items->first();

    expect((float) $item->total)->toBe(8.30)
        ->and((float) $item->vat_amount)->toBe(1.70)
        ->and((float) $item->net_override)->toBe(8.30)
        ->and((float) $note->total)->toBe(10.0);

    $edit = Livewire::test('pages::supplier-debit-notes.edit', ['debitNote' => $note]);
    expect($edit->get('items.0.net_override'))->toBe(8.3)
        ->and($edit->get('items.0.vat_override'))->toBe(1.7);

    $edit->set('items.0.vat_override', 2.00)->call('save')->assertHasNoErrors();

    $note->refresh()->load('items');
    expect((float) $note->items->first()->vat_amount)->toBe(2.0)
        ->and((float) $note->total)->toBe(10.3);
});

test('a vat override is ignored on a line without vat', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', '2026-01-15')
        ->set('items', [['details' => 'Returned', 'quantity' => 1, 'price' => 10, 'per' => 'Lot', 'discount_percent' => 0, 'vat_applicable' => false, 'vat_override' => 5]])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::with('items')->firstOrFail();

    expect((float) $note->items->first()->vat_amount)->toBe(0.0)
        ->and((float) $note->total)->toBe(10.0);
});

test('a blank line that only carries the prefilled discount is dropped on create and edit', function () {
    $supplier = Supplier::factory()->create(['trade_discount' => 2.5]);
    $blank = ['details' => '', 'quantity' => '', 'price' => '', 'per' => '', 'discount_percent' => 2.5, 'vat_applicable' => true, 'net_override' => null, 'vat_override' => null];
    $filled = ['details' => 'Returned', 'quantity' => 1, 'price' => 10, 'per' => 'Lot', 'discount_percent' => 0, 'vat_applicable' => true];

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', '2026-01-15')
        ->set('items', [$filled, $blank])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::with('items')->firstOrFail();
    expect($note->items)->toHaveCount(1);

    Livewire::test('pages::supplier-debit-notes.edit', ['debitNote' => $note])
        ->set('items', [$filled, $blank])
        ->call('save')
        ->assertHasNoErrors();

    expect($note->refresh()->items)->toHaveCount(1);
});

test('a debit note line of 10.00 inclusive with a 2.5% discount is 8.12 net plus 1.67 vat', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', '2026-01-15')
        ->set('items', [['details' => 'Returned', 'quantity' => 1, 'price' => 10, 'per' => 'Lot', 'discount_percent' => 2.5, 'vat_applicable' => true]])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::with('items')->firstOrFail();

    expect((float) $note->items->first()->total)->toBe(8.12)
        ->and((float) $note->items->first()->vat_amount)->toBe(1.67)
        ->and((float) $note->total)->toBe(9.79);
});

test('the backfill recalculates a vat-inclusive note that was saved with the old discount maths and resyncs the invoice', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 100, 'vat_applicable' => true, 'line_total' => 100, 'vat_amount' => 20]), 'items')
        ->create(['supplier_id' => $supplier->id, 'created_by' => $this->user->id, 'trade_discount' => 0]);

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'supplier_invoice_id' => $invoice->id,
        'doc_date' => now(),
        'vat_rate' => 20,
        'vat_inclusive' => true,
        'subtotal' => 0.21,
        'vat_amount' => 0.04,
        'total' => 0.25,
        'created_by' => $this->user->id,
    ]);
    $note->items()->create([
        'description' => 'Returned', 'quantity' => 1, 'amount' => 10, 'per' => 'Lot', 'discount_percent' => 2.5,
        'vat_applicable' => true, 'line_value' => 10, 'total' => 0.21, 'vat_amount' => 0.04,
    ]);
    $note->appliedInvoices()->attach($invoice->id, ['applied_amount' => 0.25, 'applied_at' => now()]);

    $this->artisan('supplier-debit-notes:backfill-deductions', ['--dry-run' => true])->assertSuccessful();
    expect((float) $note->refresh()->total)->toBe(0.25);

    $this->artisan('supplier-debit-notes:backfill-deductions')->expectsConfirmation('Apply these changes?', 'yes')->assertSuccessful();

    $note->refresh()->load('items');
    expect((float) $note->items->first()->total)->toBe(8.12)
        ->and((float) $note->items->first()->vat_amount)->toBe(1.67)
        ->and((float) $note->total)->toBe(9.79)
        ->and((float) $note->appliedInvoices()->first()->pivot->applied_amount)->toBe(9.79);

    $this->artisan('supplier-debit-notes:backfill-deductions')->expectsOutputToContain('Nothing to backfill')->assertSuccessful();
});

test('editing a debit note can move it to another invoice of the same supplier and resyncs both', function () {
    $supplier = Supplier::factory()->create();
    $makeInvoice = fn () => SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 100, 'vat_applicable' => false, 'line_total' => 100]), 'items')
        ->create(['supplier_id' => $supplier->id, 'created_by' => $this->user->id]);
    $first = $makeInvoice();
    $second = $makeInvoice();
    $line = ['details' => 'Returned', 'quantity' => 1, 'price' => 10, 'per' => 'Lot', 'discount_percent' => 0, 'vat_applicable' => false];

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', '2026-01-15')
        ->set('supplier_invoice_id', $first->id)
        ->set('items', [$line])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::firstOrFail();

    Livewire::test('pages::supplier-debit-notes.edit', ['debitNote' => $note])
        ->assertSet('supplier_invoice_id', $first->id)
        ->set('supplier_invoice_id', $second->id)
        ->call('save')
        ->assertHasNoErrors();

    expect($note->refresh()->supplier_invoice_id)->toBe($second->id)
        ->and($first->debitNotes()->count())->toBe(0)
        ->and((float) $second->debitNotes()->first()->pivot->applied_amount)->toBe(10.0);

    Livewire::test('pages::supplier-debit-notes.edit', ['debitNote' => $note])
        ->set('supplier_invoice_id', SupplierInvoice::factory()->create()->id)
        ->call('save')
        ->assertHasErrors('supplier_invoice_id');
});
