<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\PaymentAllocator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('invoice rows are capped at the loaded limit and hasMoreInvoices reflects it', function () {
    $customer = Customer::factory()->create();
    Document::factory()->count(5)->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100]);

    $component = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('loadedLimit', 3);

    expect($component->get('invoiceRows'))->toHaveCount(3)
        ->and($component->get('hasMoreInvoices'))->toBeTrue();
});

test('loadMoreInvoices raises the limit and returns previously excluded invoices', function () {
    $customer = Customer::factory()->create();
    Document::factory()->count(5)->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100]);

    $component = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('loadedLimit', 3);

    expect($component->get('invoiceRows'))->toHaveCount(3);

    $component->call('loadMoreInvoices');

    expect($component->get('invoiceRows'))->toHaveCount(5)
        ->and($component->get('hasMoreInvoices'))->toBeFalse();
});

test('searchInvoice pins a matching invoice into the rows even if it is beyond the loaded window', function () {
    $customer = Customer::factory()->create();
    Document::factory()->count(3)->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100]);
    $target = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100, 'doc_date' => now()->addYear()]);

    $component = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('loadedLimit', 1);

    expect($component->get('invoiceRows'))->toHaveCount(1);

    $component->call('searchInvoice', $target->doc_number);

    $ids = collect($component->get('invoiceRows'))->pluck('id');
    expect($ids)->toContain($target->id);
});

test('searchInvoice requires at least two characters', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id]);

    $component = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->call('searchInvoice', 'A');

    expect($component->get('extraDocumentIds'))->toBe([]);
});

test('autoAllocate distributes oldest-first across outstanding invoices beyond the loaded window', function () {
    $customer = Customer::factory()->create();
    $old = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100, 'doc_date' => now()->subDays(10)]);
    $new = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100, 'doc_date' => now()]);

    $component = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('amount', '150')
        ->set('loadedLimit', 1);

    expect($component->get('invoiceRows'))->toHaveCount(1);

    $component->call('autoAllocate');

    $ids = collect($component->get('invoiceRows'))->keyBy('id');
    expect($ids)->toHaveKey($old->id)
        ->and($ids)->toHaveKey($new->id);
});

test('autoAllocate uses the explicitly passed amount instead of a stale server-side amount', function () {
    // The amount field uses wire:model.blur, so $this->amount can lag behind
    // what the user just typed when they click straight into Auto Allocate
    // without blurring first. The client passes its live value explicitly —
    // that must win over whatever the server still has stored.
    $customer = Customer::factory()->create();
    $invoice = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100]);

    $component = Livewire::test('pages::payments.form')
        ->set('customer_id', $customer->id)
        ->set('amount', '10'); // stale value still on the server

    $component->call('autoAllocate', '100'); // fresh value passed explicitly

    $component->assertDispatched('payment-auto-allocated', function ($name, $params) use ($invoice) {
        return (float) $params['allocations'][(string) $invoice->id] === 100.0;
    });
});

test('PaymentAllocator autoAllocate treats a payment\'s own prior allocation as free to redistribute', function () {
    $customer = Customer::factory()->create();
    $cashMethod = LookupPaymentMethod::factory()->create();
    $invoice = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100]);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $cashMethod->id,
        'amount' => 40,
    ]);
    PaymentAllocation::create(['payment_id' => $payment->id, 'document_id' => $invoice->id, 'allocated_amount' => 40]);

    // Re-running auto-allocate for this same payment at a larger amount should
    // still be able to claim the full £100 outstanding on this invoice — its
    // own prior £40 must not count as "already consumed by someone else".
    $payment->amount = 100;
    $allocations = app(PaymentAllocator::class)->autoAllocate($payment);

    expect($allocations)->toBe([$invoice->id => 100.0]);
});
