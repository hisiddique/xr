<?php

use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('payment-only allocation leaves invoice not fully settled', function () {
    $customer = Customer::factory()->create();
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 1200,
    ]);
    $payment = Payment::factory()->create(['customer_id' => $customer->id]);

    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 800,
    ]);

    $rows = Livewire::test('pages::payments.show', ['payment' => $payment])
        ->get('invoiceRows');

    expect($rows)->toHaveCount(1);
    $row = $rows[0];

    expect($row['existing_allocation'])->toBe(800.0)
        ->and($row['credit_notes'])->toBe([])
        ->and($row['outstanding'])->toBe(400.0)
        ->and($row['is_settled'])->toBeFalse();
});

test('payment plus credit note combo exactly settles invoice', function () {
    $customer = Customer::factory()->create();
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 1200,
    ]);
    $creditNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
    ]);
    $payment = Payment::factory()->create(['customer_id' => $customer->id]);

    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 1000,
    ]);

    CreditAllocation::create([
        'payment_id' => $payment->id,
        'credit_note_id' => $creditNote->id,
        'invoice_id' => $invoice->id,
        'amount' => 200,
    ]);

    $rows = Livewire::test('pages::payments.show', ['payment' => $payment])
        ->get('invoiceRows');

    expect($rows)->toHaveCount(1);
    $row = $rows[0];

    expect($row['existing_allocation'])->toBe(1000.0)
        ->and($row['credit_notes'])->toHaveCount(1)
        ->and($row['credit_notes'][0]['reference'])->toBe($creditNote->doc_number)
        ->and($row['credit_notes'][0]['amount'])->toBe(200.0)
        ->and((float) $row['outstanding'])->toBe(0.0)
        ->and($row['is_settled'])->toBeTrue();
});

test('credit-note-only allocation still surfaces the invoice row', function () {
    $customer = Customer::factory()->create();
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 1200,
    ]);
    $creditNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
    ]);
    $payment = Payment::factory()->create(['customer_id' => $customer->id]);

    CreditAllocation::create([
        'payment_id' => $payment->id,
        'credit_note_id' => $creditNote->id,
        'invoice_id' => $invoice->id,
        'amount' => 300,
    ]);

    $rows = Livewire::test('pages::payments.show', ['payment' => $payment])
        ->get('invoiceRows');

    expect($rows)->toHaveCount(1);
    $row = $rows[0];

    expect($row['existing_allocation'])->toBe(0.0)
        ->and($row['credit_notes'])->toHaveCount(1)
        ->and($row['credit_amount'])->toBe(300.0);
});

test('totalOutstanding sums only unsettled invoices across multiple invoices', function () {
    $customer = Customer::factory()->create();
    $payment = Payment::factory()->create(['customer_id' => $customer->id]);

    $unsettledInvoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 1200,
    ]);
    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $unsettledInvoice->id,
        'allocated_amount' => 800,
    ]);

    $settledInvoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 500,
    ]);
    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $settledInvoice->id,
        'allocated_amount' => 500,
    ]);

    $component = Livewire::test('pages::payments.show', ['payment' => $payment]);

    expect($component->get('totalOutstanding'))->toBe(400.0);
});

test('outstanding balance reflects all-time allocations, not just this payment', function () {
    $customer = Customer::factory()->create();
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 1200,
    ]);

    $otherPayment = Payment::factory()->create(['customer_id' => $customer->id]);
    PaymentAllocation::create([
        'payment_id' => $otherPayment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 500,
    ]);

    $payment = Payment::factory()->create(['customer_id' => $customer->id]);
    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 500,
    ]);

    $rows = Livewire::test('pages::payments.show', ['payment' => $payment])
        ->get('invoiceRows');

    expect($rows)->toHaveCount(1);
    expect($rows[0]['outstanding'])->toBe(200.0);
});
