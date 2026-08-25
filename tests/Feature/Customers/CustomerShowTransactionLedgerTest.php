<?php

use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDraw;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('transaction ledger paginates according to perPage', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Document::factory()->invoice()->count(3)->sequence(
        ['doc_number' => 'INV-0001', 'order_no' => 'ORD-1'],
        ['doc_number' => 'INV-0002', 'order_no' => 'ORD-2'],
        ['doc_number' => 'INV-0003', 'order_no' => 'ORD-3'],
    )->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer]);
    $component->set('perPage', 2);

    $ledger = $component->instance()->transactionLedger();

    expect($ledger->total())->toBe(3)
        ->and($ledger->count())->toBe(2)
        ->and($ledger->perPage())->toBe(2);
});

test('transaction ledger search filters by reference number', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-1001',
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-2002',
        'total_value' => 150,
        'doc_date' => now(),
    ]);

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer]);
    $component->set('ledgerSearch', '1001');

    $ledger = $component->instance()->transactionLedger();

    expect($ledger->total())->toBe(1)
        ->and($ledger->first()['ref_no'])->toBe('INV-1001');
});

test('changing ledger search resets the ledger page', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Document::factory()->invoice()->count(3)->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer])
        ->set('perPage', 2)
        ->call('$set', 'paginators.ledger_page', 2)
        ->set('ledgerSearch', 'foo')
        ->assertSet('paginators.ledger_page', 1);
});

test('ledger rows expose invoice details and status even without any allocations', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-3001',
        'total_value' => 250,
        'doc_date' => now(),
    ]);

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer]);
    $row = $component->instance()->transactionLedger()->first();

    expect($row['status'])->toBe('outstanding')
        ->and($row['outstanding'])->toBe(250.0)
        ->and($row['details']['kind'])->toBe('invoice')
        ->and($row['details']['allocations'])->toBe([]);
});

test('ledger derives applied, partial, and unapplied status for credit notes', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    $appliedNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'CN-APPLIED',
        'total_value' => 40,
        'doc_date' => now(),
    ]);
    CreditAllocation::create([
        'credit_note_id' => $appliedNote->id,
        'invoice_id' => $invoice->id,
        'amount' => 40,
    ]);

    $partialNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'CN-PARTIAL',
        'total_value' => 50,
        'doc_date' => now(),
    ]);
    CreditAllocation::create([
        'credit_note_id' => $partialNote->id,
        'invoice_id' => $invoice->id,
        'amount' => 20,
    ]);

    Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'CN-UNAPPLIED',
        'total_value' => 30,
        'doc_date' => now(),
    ]);

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer]);
    $rows = $component->instance()->transactionLedger()->getCollection()->keyBy('ref_no');

    expect($rows['CN-APPLIED']['status'])->toBe('applied')
        ->and($rows['CN-APPLIED']['details']['outstanding'])->toBe(0.0)
        ->and($rows['CN-PARTIAL']['status'])->toBe('partial')
        ->and($rows['CN-PARTIAL']['details']['outstanding'])->toBe(30.0)
        ->and($rows['CN-UNAPPLIED']['status'])->toBe('unapplied')
        ->and($rows['CN-UNAPPLIED']['details']['outstanding'])->toBe(30.0);
});

test('ledger payment status and unallocated amount account for draws made on the payment', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'reference' => 'PAY-9001',
        'amount' => 100,
        'payment_date' => now(),
    ]);

    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 30,
    ]);

    $otherPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $payment->payment_method_id,
        'amount' => 20,
        'payment_date' => now(),
    ]);

    PaymentDraw::create([
        'source_payment_id' => $payment->id,
        'target_payment_id' => $otherPayment->id,
        'amount' => 40,
    ]);

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer]);
    $row = $component->instance()->transactionLedger()->getCollection()->firstWhere('ref_no', 'PAY-9001');

    expect($row['status'])->toBe('partial')
        ->and($row['details']['unallocated'])->toBe(30.0)
        ->and($row['outstanding'])->toBe(-30.0);
});

test('a payment fully consumed by draws to other payments is marked drawn, not applied', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'reference' => 'PAY-DRAWN',
        'amount' => 50,
        'payment_date' => now(),
    ]);

    $otherPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $payment->payment_method_id,
        'amount' => 20,
        'payment_date' => now(),
    ]);

    PaymentDraw::create([
        'source_payment_id' => $payment->id,
        'target_payment_id' => $otherPayment->id,
        'amount' => 50,
    ]);

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer]);
    $row = $component->instance()->transactionLedger()->getCollection()->firstWhere('ref_no', 'PAY-DRAWN');

    expect($row['status'])->toBe('drawn')
        ->and($row['details']['allocations'])->toBe([]);
});

test('credit allocations applied to a payment rather than an invoice do not crash the ledger', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    $creditNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'CN-PAYMENT-LINKED',
        'total_value' => 30,
        'doc_date' => now(),
    ]);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'amount' => 30,
        'payment_date' => now(),
    ]);

    CreditAllocation::create([
        'credit_note_id' => $creditNote->id,
        'payment_id' => $payment->id,
        'invoice_id' => null,
        'amount' => 30,
    ]);

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer]);
    $ledger = $component->instance()->transactionLedger();

    $creditRow = $ledger->getCollection()->firstWhere('ref_no', 'CN-PAYMENT-LINKED');

    expect($ledger)->not->toBeNull()
        ->and($creditRow['details']['applied_to'])->toBe([]);
});
