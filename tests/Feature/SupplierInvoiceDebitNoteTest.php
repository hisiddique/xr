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

test('attaching a debit note stores applied_amount on the pivot', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 200, 'vat_applicable' => false, 'line_total' => 200]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 50,
        'total' => 50,
        'created_by' => $this->user->id,
    ]);

    $invoice->debitNotes()->attach($debitNote->id, ['applied_amount' => 50, 'applied_at' => now()]);

    $invoice->load('debitNotes');
    $pivot = $invoice->debitNotes->first()->pivot;

    expect((float) $pivot->applied_amount)->toBe(50.0);
});

test('outstandingAmount reflects deduction from debit note pivot', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 200, 'vat_applicable' => false, 'line_total' => 200]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 60,
        'total' => 60,
        'created_by' => $this->user->id,
    ]);

    $invoice->debitNotes()->attach($debitNote->id, ['applied_amount' => 60, 'applied_at' => now()]);

    $invoice->load('items', 'payoutAllocations', 'debitNotes');

    expect($invoice->outstandingAmount)->toBe(140.0);
});

test('outstandingAmount combines grossTotal minus payout allocations and debit note deductions', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 300, 'vat_applicable' => false, 'line_total' => 300]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 50,
        'total' => 50,
        'created_by' => $this->user->id,
    ]);

    $invoice->debitNotes()->attach($debitNote->id, ['applied_amount' => 50, 'applied_at' => now()]);

    $payout = SupplierPayout::create([
        'supplier_id' => $this->supplier->id,
        'amount' => 100,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $invoice->id,
        'deduction_amount' => 0,
        'allocated_amount' => 100,
    ]);

    $invoice->load('items', 'payoutAllocations', 'debitNotes');

    expect($invoice->outstandingAmount)->toBe(150.0);
});

test('detaching a debit note removes it from pivot and outstandingAmount recovers', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 200, 'vat_applicable' => false, 'line_total' => 200]), 'items')
        ->create(['supplier_id' => $this->supplier->id, 'created_by' => $this->user->id]);

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $this->supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 80,
        'total' => 80,
        'created_by' => $this->user->id,
    ]);

    $invoice->debitNotes()->attach($debitNote->id, ['applied_amount' => 80, 'applied_at' => now()]);

    $invoice->load('items', 'payoutAllocations', 'debitNotes');
    expect($invoice->outstandingAmount)->toBe(120.0);

    $invoice->debitNotes()->detach($debitNote->id);

    $invoice->load('items', 'payoutAllocations', 'debitNotes');
    expect($invoice->outstandingAmount)->toBe(200.0);
});
