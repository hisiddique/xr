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

test('autoAllocate pulls in auto-allocated invoices from beyond the loaded window', function () {
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

test('autoAllocate settles an exact single invoice and touches nothing else', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 200, 'doc_date' => now()->subDays(3)]);
    $inv100 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100, 'doc_date' => now()->subDays(2)]);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 50, 'doc_date' => now()->subDays(1)]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 100]));

    expect($allocations)->toBe([$inv100->id => 100.0]);
});

test('autoAllocate settles each invoice of an exact multi-invoice subset in full', function () {
    $customer = Customer::factory()->create();
    $inv200 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 200, 'doc_date' => now()->subDays(5)]);
    $inv75 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 75, 'doc_date' => now()->subDays(4)]);
    $inv29 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 29, 'doc_date' => now()->subDays(3)]);
    $inv33 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 33, 'doc_date' => now()->subDays(2)]);
    $inv25 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 25, 'doc_date' => now()->subDays(1)]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 100]));

    expect(collect($allocations)->sortKeys()->all())->toEqual(collect([$inv75->id => 75.0, $inv25->id => 25.0])->sortKeys()->all())
        ->and($allocations)->not->toHaveKey($inv200->id)
        ->and($allocations)->not->toHaveKey($inv29->id)
        ->and($allocations)->not->toHaveKey($inv33->id);
});

test('autoAllocate prefers the fewest invoices for an exact subset', function () {
    $customer = Customer::factory()->create();
    $inv50a = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 50, 'doc_date' => now()->subDays(4)]);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 30, 'doc_date' => now()->subDays(3)]);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 20, 'doc_date' => now()->subDays(2)]);
    $inv50b = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 50, 'doc_date' => now()->subDays(1)]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 50]));

    expect($allocations)->toHaveCount(1)
        ->and(array_values($allocations))->toBe([50.0])
        ->and(array_key_first($allocations))->toBeIn([$inv50a->id, $inv50b->id]);
});

test('autoAllocate with no exact subset falls back to smallest-first with a partial tail', function () {
    $customer = Customer::factory()->create();
    $inv55 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 55, 'doc_date' => now()->subDays(3)]);
    $inv40 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 40, 'doc_date' => now()->subDays(2)]);
    $inv35 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 35, 'doc_date' => now()->subDays(1)]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 100]));

    expect($allocations)->toBe([$inv35->id => 35.0, $inv40->id => 40.0, $inv55->id => 25.0]);
});

test('autoAllocate settles everything when the amount exceeds total outstanding', function () {
    $customer = Customer::factory()->create();
    $inv10 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 10, 'doc_date' => now()->subDays(2)]);
    $inv20 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 20, 'doc_date' => now()->subDays(1)]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 100]));

    expect($allocations)->toEqual([$inv10->id => 10.0, $inv20->id => 20.0])
        ->and(array_sum($allocations))->toBe(30.0);
});

test('autoAllocate exact search ignores invoices larger than the payment', function () {
    $customer = Customer::factory()->create();
    $inv1000 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 1000, 'doc_date' => now()->subDays(3)]);
    $inv40 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 40, 'doc_date' => now()->subDays(2)]);
    $inv60 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 60, 'doc_date' => now()->subDays(1)]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 100]));

    expect(collect($allocations)->sortKeys()->all())->toEqual(collect([$inv40->id => 40.0, $inv60->id => 60.0])->sortKeys()->all())
        ->and($allocations)->not->toHaveKey($inv1000->id);
});

test('autoAllocate matches an exact subset on cents without float drift', function () {
    $customer = Customer::factory()->create();
    $inv3333 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 33.33, 'doc_date' => now()->subDays(3)]);
    $inv6667 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 66.67, 'doc_date' => now()->subDays(2)]);
    $inv500 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 500, 'doc_date' => now()->subDays(1)]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 100.00]));

    expect(collect($allocations)->sortKeys()->all())->toEqual(collect([$inv3333->id => 33.33, $inv6667->id => 66.67])->sortKeys()->all())
        ->and($allocations)->not->toHaveKey($inv500->id);
});

test('autoAllocate degrades to a valid fallback allocation beyond the candidate cap', function () {
    $customer = Customer::factory()->create();

    for ($i = 0; $i < 30; $i++) {
        Document::factory()->invoice()->create([
            'customer_id' => $customer->id,
            'total_value' => 7,
            'doc_date' => now()->subDays(30 - $i),
        ]);
    }

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 100]));

    expect(array_sum($allocations))->toEqualWithDelta(100.0, 0.001)
        ->and($allocations)->toHaveCount(15)
        ->and(collect($allocations)->filter(fn ($value) => $value === 7.0)->count())->toBe(14)
        ->and(collect($allocations)->filter(fn ($value) => $value === 2.0)->count())->toBe(1);
});

test('autoAllocate excludes invoices that are already fully settled', function () {
    $customer = Customer::factory()->create();
    $settled = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100, 'doc_date' => now()->subDays(2)]);
    $open = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 50, 'doc_date' => now()->subDays(1)]);

    $other = Payment::factory()->create(['customer_id' => $customer->id]);
    PaymentAllocation::create(['payment_id' => $other->id, 'document_id' => $settled->id, 'allocated_amount' => 100]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 50]));

    expect($allocations)->toBe([$open->id => 50.0]);
});

test('autoAllocate returns an empty array for a zero or negative amount', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100]);

    $allocations = app(PaymentAllocator::class)->autoAllocate(new Payment(['customer_id' => $customer->id, 'amount' => 0]));

    expect($allocations)->toBe([]);
});
