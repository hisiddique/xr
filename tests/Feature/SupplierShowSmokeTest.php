<?php

use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierPayout;
use App\Models\User;
use App\SupplierDebitNoteStatus;

test('supplier show route renders invoices, debit notes and payouts tabs', function () {
    $user = User::factory()->create();
    $supplier = Supplier::factory()->create();

    SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $user->id,
    ]);

    SupplierPayout::create([
        'supplier_id' => $supplier->id,
        'amount' => 200,
        'payout_date' => now(),
        'created_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->get(route('suppliers.show', $supplier).'?tab=invoices')
        ->assertOk()
        ->assertSee('Invoices');

    $this->actingAs($user)
        ->get(route('suppliers.show', $supplier).'?tab=debit-notes')
        ->assertOk()
        ->assertSee('Debit Notes');

    $this->actingAs($user)
        ->get(route('suppliers.show', $supplier).'?tab=payouts')
        ->assertOk()
        ->assertSee('Payouts');
});

test('suppliers index renders quick link dropdown to invoices, debit notes and payouts', function () {
    $user = User::factory()->create();
    Supplier::factory()->create();

    $this->actingAs($user)
        ->get(route('suppliers.index'))
        ->assertOk()
        ->assertSee(route('supplier-invoices.index'), false)
        ->assertSee(route('supplier-debit-notes.index'), false)
        ->assertSee(route('supplier-payouts.index'), false);
});
