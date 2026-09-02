<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Models\User;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->supplier = Supplier::factory()->create();

    Setting::updateOrCreate(['key' => 'supinv_prefix'], ['key' => 'supinv_prefix', 'value' => 'SUPINV', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'number_padding'], ['key' => 'number_padding', 'value' => '4', 'type' => 'integer']);
    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => '20', 'type' => 'float']);
    Setting::flushCache();
});

test('transaction history tab renders invoices, debit notes and payouts', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => false, 'line_total' => 1000]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 600,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $this->get(route('suppliers.show', $this->supplier).'?tab=transaction-history')
        ->assertOk()
        ->assertSee('Transaction History')
        ->assertSee($invoice->supplier_invoice_no)
        ->assertSee($debitNote->reference)
        ->assertSee($payout->reference);
});

test('legacy invoices, debit notes and payouts tabs still render', function () {
    SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => false, 'line_total' => 1000]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 600,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $this->get(route('suppliers.show', $this->supplier).'?tab=invoices')->assertOk();
    $this->get(route('suppliers.show', $this->supplier).'?tab=debit-notes')->assertOk();
    $this->get(route('suppliers.show', $this->supplier).'?tab=payouts')->assertOk();
});

test('ledger search filters rows by reference', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => false, 'line_total' => 1000]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 600,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::suppliers.show', ['supplier' => $this->supplier])
        ->set('ledgerSearch', $invoice->supplier_invoice_no)
        ->assertSee($invoice->supplier_invoice_no)
        ->assertDontSee($payout->reference);
});

test('sortBy cycles asc, desc then clears', function () {
    Livewire::test('pages::suppliers.show', ['supplier' => $this->supplier])
        ->call('sortBy', 'amount')
        ->assertSet('sortColumn', 'amount')
        ->assertSet('sortDirection', 'asc')
        ->call('sortBy', 'amount')
        ->assertSet('sortDirection', 'desc')
        ->call('sortBy', 'amount')
        ->assertSet('sortColumn', '');
});

test('expanding a ledger row shows linked document detail', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => false, 'line_total' => 1000]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 600,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $invoice->id,
        'deduction_amount' => 0,
        'allocated_amount' => 600,
    ]);

    $this->get(route('suppliers.show', $this->supplier).'?tab=transaction-history')
        ->assertOk()
        ->assertSee('Invoice Details:')
        ->assertSee($payout->reference);
});

test('deduction_amount is not double counted in paid or outstanding', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 1000, 'vat_applicable' => false, 'line_total' => 1000]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 600,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $invoice->id,
        'deduction_amount' => 100,
        'allocated_amount' => 600,
    ]);

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    $invoice->debitNotes()->attach($debitNote->id, ['applied_amount' => 100, 'applied_at' => now()]);

    expect($invoice->fresh()->loadMissing('items', 'payoutAllocations', 'debitNotes')->outstandingAmount)->toBe(300.0);

    $rows = Livewire::test('pages::suppliers.show', ['supplier' => $this->supplier])
        ->instance()->buildSupplierLedgerRows['rows'];

    $invoiceRow = collect($rows)->firstWhere('type', 'supplier_invoice');

    expect($invoiceRow['details']['outstanding'])->toBe(300.0);
    expect($invoiceRow['details']['paid'])->toBe(700.0);
});

test('payout allocated to a debit note renders without error', function () {
    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 600,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_debit_note_id' => $debitNote->id,
        'supplier_invoice_id' => null,
        'deduction_amount' => 0,
        'allocated_amount' => 50,
    ]);

    $this->get(route('suppliers.show', $this->supplier).'?tab=transaction-history')
        ->assertOk()
        ->assertSee($debitNote->reference);
});
