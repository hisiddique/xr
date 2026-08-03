<?php

use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentDraw;
use App\Models\User;
use App\PaymentSourceType;
use App\Services\PaymentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('credit note is consumed in full even when partially applied to invoices, and leftover becomes over-payment eligible', function () {
    $customer = Customer::factory()->create();
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 60,
    ]);
    $creditNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
    ]);

    Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('paymentMethodSelection', 'credit_note')
        ->set('payment_date', now()->format('Y-m-d'))
        ->set('selectedCreditNoteIds', [$creditNote->id])
        ->call('save', [['id' => $invoice->id, 'amount' => 60]]);

    $payment = Payment::where('customer_id', $customer->id)->sole();

    expect($payment->source_type)->toBe(PaymentSourceType::CreditNote)
        ->and((float) $payment->amount)->toBe(100.0)
        ->and((float) $payment->allocations()->sum('allocated_amount'))->toBe(60.0)
        ->and($payment->remainingBalance())->toBe(40.0);

    expect((float) CreditAllocation::where('credit_note_id', $creditNote->id)->sum('amount'))->toBe(100.0);

    // The note is fully consumed — unavailable to a second payment for the same customer.
    $secondForm = Livewire::test('pages::payments.form')->set('customer_id', $customer->id);
    expect($secondForm->get('availableCreditNotes'))->toBe([]);

    // Its £40 leftover is eligible as an over-payment source.
    $overPaymentSources = $secondForm->set('paymentMethodSelection', 'over_payment')->get('availableOverPaymentSources');
    expect($overPaymentSources)->toHaveCount(1)
        ->and($overPaymentSources[0]['id'])->toBe($payment->id)
        ->and($overPaymentSources[0]['remaining'])->toBe(40.0);
});

test('a credit note with legacy partial usage still appears with its true remaining balance', function () {
    $customer = Customer::factory()->create();
    $cashMethod = LookupPaymentMethod::factory()->create();
    $legacyInvoice = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 110]);
    $legacyPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $cashMethod->id,
        'source_type' => PaymentSourceType::Cash,
        'amount' => 500,
    ]);
    $creditNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'total_value' => 1201.36,
    ]);
    CreditAllocation::create([
        'payment_id' => $legacyPayment->id,
        'credit_note_id' => $creditNote->id,
        'invoice_id' => $legacyInvoice->id,
        'amount' => 110,
    ]);

    $available = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('paymentMethodSelection', 'credit_note')
        ->get('availableCreditNotes');

    expect($available)->toHaveCount(1)
        ->and($available[0]['id'])->toBe($creditNote->id)
        ->and($available[0]['remaining'])->toBe(1091.36);

    $newInvoice = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 200]);

    Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('paymentMethodSelection', 'credit_note')
        ->set('payment_date', now()->format('Y-m-d'))
        ->set('selectedCreditNoteIds', [$creditNote->id])
        ->call('save', [['id' => $newInvoice->id, 'amount' => 200]]);

    $newPayment = Payment::where('customer_id', $customer->id)->where('source_type', PaymentSourceType::CreditNote)->sole();

    expect((float) $newPayment->amount)->toBe(1091.36)
        ->and((float) CreditAllocation::where('credit_note_id', $creditNote->id)->sum('amount'))->toBe(1201.36);

    expect(Livewire::test('pages::payments.form')->set('customer_id', $customer->id)->get('availableCreditNotes'))->toBe([]);
});

test('over-payment funded payments cannot be drawn from again, but credit-note funded ones can', function () {
    $customer = Customer::factory()->create();
    $cashMethod = LookupPaymentMethod::factory()->create();

    $cashPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $cashMethod->id,
        'source_type' => PaymentSourceType::Cash,
        'amount' => 300,
    ]);

    $creditNote = Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'total_value' => 200,
    ]);
    $creditNotePayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => null,
        'source_type' => PaymentSourceType::CreditNote,
        'amount' => 200,
    ]);
    CreditAllocation::create([
        'payment_id' => $creditNotePayment->id,
        'credit_note_id' => $creditNote->id,
        'invoice_id' => null,
        'amount' => 200,
    ]);

    $overPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => null,
        'source_type' => PaymentSourceType::OverPayment,
        'amount' => 300,
    ]);
    PaymentDraw::create(['source_payment_id' => $cashPayment->id, 'target_payment_id' => $overPayment->id, 'amount' => 300]);

    $sources = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('paymentMethodSelection', 'over_payment')
        ->get('availableOverPaymentSources');

    $ids = collect($sources)->pluck('id');

    expect($ids)->toContain($creditNotePayment->id)
        ->not->toContain($overPayment->id)
        ->not->toContain($cashPayment->id); // fully drawn by the over-payment, no remaining balance
});

test('marking a source payment as exhausted removes it from the over-payment picker and is reversible', function () {
    $customer = Customer::factory()->create();
    $cashMethod = LookupPaymentMethod::factory()->create();
    $source = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $cashMethod->id,
        'source_type' => PaymentSourceType::Cash,
        'amount' => 150,
    ]);

    Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('paymentMethodSelection', 'over_payment')
        ->set('payment_date', now()->format('Y-m-d'))
        ->set('selectedOverPaymentIds', [$source->id])
        ->set('overPaymentExhaustIds', [$source->id])
        ->call('save', []);

    expect($source->fresh()->is_exhausted)->toBeTrue();

    $availableAfter = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('paymentMethodSelection', 'over_payment')
        ->get('availableOverPaymentSources');

    expect($availableAfter)->toBe([]);
});

test('re-saving an over-payment with the same source does not throw and keeps a single draw row', function () {
    $customer = Customer::factory()->create();
    $cashMethod = LookupPaymentMethod::factory()->create();
    $source = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $cashMethod->id,
        'source_type' => PaymentSourceType::Cash,
        'amount' => 200,
    ]);

    $overPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => null,
        'source_type' => PaymentSourceType::OverPayment,
        'amount' => 200,
    ]);
    PaymentDraw::create(['source_payment_id' => $source->id, 'target_payment_id' => $overPayment->id, 'amount' => 200]);

    Livewire::test('pages::payments.form', ['payment' => $overPayment])
        ->set('selectedOverPaymentIds', [$source->id])
        ->call('save', []);

    expect(PaymentDraw::where('target_payment_id', $overPayment->id)->count())->toBe(1)
        ->and((float) $overPayment->fresh()->amount)->toBe(200.0);
});

test('switching a payment from over-payment back to cash releases its draws', function () {
    $customer = Customer::factory()->create();
    $cashMethod = LookupPaymentMethod::factory()->create();
    $source = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $cashMethod->id,
        'source_type' => PaymentSourceType::Cash,
        'amount' => 200,
    ]);

    $overPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => null,
        'source_type' => PaymentSourceType::OverPayment,
        'amount' => 200,
    ]);
    PaymentDraw::create(['source_payment_id' => $source->id, 'target_payment_id' => $overPayment->id, 'amount' => 200]);

    Livewire::test('pages::payments.form', ['payment' => $overPayment])
        ->set('paymentMethodSelection', "lookup:{$cashMethod->id}")
        ->set('amount', '75')
        ->call('save', []);

    expect(PaymentDraw::where('target_payment_id', $overPayment->id)->count())->toBe(0)
        ->and($source->fresh()->remainingBalance())->toBe(200.0);
});

test('a payment cannot allocate more than its amount minus what has been drawn from it as an over-payment source', function () {
    $customer = Customer::factory()->create();
    $cashMethod = LookupPaymentMethod::factory()->create();
    $source = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $cashMethod->id,
        'source_type' => PaymentSourceType::Cash,
        'amount' => 300,
    ]);
    $overPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => null,
        'source_type' => PaymentSourceType::OverPayment,
        'amount' => 300,
    ]);
    PaymentDraw::create(['source_payment_id' => $source->id, 'target_payment_id' => $overPayment->id, 'amount' => 300]);

    $invoice = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 300]);

    app(PaymentAllocator::class)->saveAllocations($source, [$invoice->id => 300]);
})->throws(InvalidArgumentException::class);

test('deleting a payment cleans up its credit allocations and payment draws', function () {
    $customer = Customer::factory()->create();

    $creditNote = Document::factory()->creditNote()->create(['customer_id' => $customer->id, 'total_value' => 100]);
    $creditNotePayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => null,
        'source_type' => PaymentSourceType::CreditNote,
        'amount' => 100,
    ]);
    CreditAllocation::create([
        'payment_id' => $creditNotePayment->id,
        'credit_note_id' => $creditNote->id,
        'invoice_id' => null,
        'amount' => 100,
    ]);

    $cashMethod = LookupPaymentMethod::factory()->create();
    $cashPayment = Payment::factory()->create(['customer_id' => $customer->id, 'payment_method_id' => $cashMethod->id, 'amount' => 50]);
    $overPayment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => null,
        'source_type' => PaymentSourceType::OverPayment,
        'amount' => 50,
    ]);
    PaymentDraw::create(['source_payment_id' => $cashPayment->id, 'target_payment_id' => $overPayment->id, 'amount' => 50]);

    $creditNotePayment->delete();
    $overPayment->delete();

    expect(CreditAllocation::where('payment_id', $creditNotePayment->id)->count())->toBe(0)
        ->and(PaymentDraw::where('target_payment_id', $overPayment->id)->count())->toBe(0);
});
