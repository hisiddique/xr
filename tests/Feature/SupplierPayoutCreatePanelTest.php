<?php

use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Models\User;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->create());
});

function makeCommittedDebitNote(Supplier $supplier, float $total = 50): SupplierDebitNote
{
    return SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => $total,
        'vat_amount' => 0,
        'total' => $total,
    ]);
}

test('create panel hydrates the supplier and committed debit notes from the dn query param', function () {
    $supplier = Supplier::factory()->create();
    $dnA = makeCommittedDebitNote($supplier);
    $dnB = makeCommittedDebitNote($supplier);

    Livewire::withQueryParams(['dn' => "{$dnA->id},{$dnB->id}"])
        ->test('pages::supplier-payouts.create-panel')
        ->assertSet('supplier_id', $supplier->id)
        ->assertSet('committedDebitNotes', [
            ['id' => $dnA->id, 'linked_invoice_id' => null],
            ['id' => $dnB->id, 'linked_invoice_id' => null],
        ]);
});

test('create panel ignores the hand-off when debit notes span multiple suppliers', function () {
    $dnA = makeCommittedDebitNote(Supplier::factory()->create());
    $dnB = makeCommittedDebitNote(Supplier::factory()->create());

    Livewire::withQueryParams(['dn' => "{$dnA->id},{$dnB->id}"])
        ->test('pages::supplier-payouts.create-panel')
        ->assertSet('supplier_id', null)
        ->assertSet('committedDebitNotes', [])
        ->assertSet('dn', '');
});

test('confirming the payout clears the dn query param', function () {
    $supplier = Supplier::factory()->create();
    $dn = makeCommittedDebitNote($supplier, 50);

    Livewire::withQueryParams(['dn' => (string) $dn->id])
        ->test('pages::supplier-payouts.create-panel')
        ->assertSet('dn', (string) $dn->id)
        ->set('amount', '50')
        ->call('confirmAllocation', [
            ['id' => null, 'debit_note_id' => $dn->id, 'deductions' => 50, 'allocated_amount' => 50],
        ])
        ->assertSet('dn', '');

    expect(SupplierPayoutAllocation::where('supplier_debit_note_id', $dn->id)->exists())->toBeTrue();
});

test('create panel ignores a debit note that is already part of a payout', function () {
    $supplier = Supplier::factory()->create();
    $dn = makeCommittedDebitNote($supplier);

    $payout = SupplierPayout::create([
        'supplier_id' => $supplier->id,
        'amount' => 50,
        'payout_date' => now(),
    ]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_debit_note_id' => $dn->id,
        'deduction_amount' => 50,
        'allocated_amount' => 0,
    ]);

    Livewire::withQueryParams(['dn' => (string) $dn->id])
        ->test('pages::supplier-payouts.create-panel')
        ->assertSet('supplier_id', null)
        ->assertSet('committedDebitNotes', [])
        ->assertSet('dn', '');
});
