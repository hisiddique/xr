<?php

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->supplier = Supplier::factory()->create();
    $this->invoice = SupplierInvoice::factory()->create(['supplier_id' => $this->supplier->id]);
    SupplierInvoiceItem::factory()->create(['supplier_invoice_id' => $this->invoice->id, 'quantity' => 1, 'unit_amount' => 100, 'line_total' => 100]);
});

test('show page links to create payout and debit note for the invoice supplier', function () {
    Livewire::test('pages::supplier-invoices.show', ['supplierInvoice' => $this->invoice])
        ->assertSee(route('supplier-payouts.create', ['supplier_id' => $this->supplier->id]), false)
        ->assertSee('Debit Note')
        ->assertSee(route('supplier-debit-notes.create', ['supplier_id' => $this->supplier->id, 'supplier_invoice_id' => $this->invoice->id]));
});

test('paid invoice links to the payout that settled it and unpaid invoice does not', function () {
    Livewire::test('pages::supplier-invoices.show', ['supplierInvoice' => $this->invoice])
        ->assertDontSee('Paid By');

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 1000,
        'payout_date' => now(),
    ]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $this->invoice->id,
        'allocated_amount' => 1000,
        'deduction_amount' => 0,
    ]);

    Livewire::test('pages::supplier-invoices.show', ['supplierInvoice' => $this->invoice->fresh()])
        ->assertSee('Paid By')
        ->assertSee($payout->reference)
        ->assertSee(route('supplier-payouts.show', $payout), false);
});

test('payout panel prefills the supplier from the query string', function () {
    Livewire::withQueryParams(['supplier_id' => $this->supplier->id])
        ->test('pages::supplier-payouts.create-panel')
        ->assertSet('supplier_id', $this->supplier->id);
});

test('debit note create prefills supplier and ignores invoices that are not linkable', function () {
    $other = SupplierInvoice::factory()->create();

    Livewire::withQueryParams(['supplier_id' => $this->supplier->id, 'supplier_invoice_id' => $other->id])
        ->test('pages::supplier-debit-notes.create')
        ->assertSet('supplier_id', $this->supplier->id)
        ->assertSet('supplier_invoice_id', null);
});

test('confirming a payout with no allocations is blocked', function () {
    Livewire::test('pages::supplier-payouts.create-panel')
        ->set('supplier_id', $this->supplier->id)
        ->set('amount', '100')
        ->call('confirmAllocation', [])
        ->assertDispatched('toast-show');

    expect(SupplierPayout::count())->toBe(0);

    Livewire::test('pages::supplier-payouts.create-panel')
        ->set('supplier_id', $this->supplier->id)
        ->set('amount', '100')
        ->call('confirmAllocation', [['id' => $this->invoice->id, 'allocated_amount' => 0, 'deductions' => 0]]);

    expect(SupplierPayout::count())->toBe(0);
});
