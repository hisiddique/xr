<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('customer show page computes balance and on account from partially allocated invoices and payments', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    $payment = Payment::factory()->create([
        'customer_id' => $customer->id,
        'amount' => 60,
        'payment_date' => now(),
    ]);

    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 40,
    ]);

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $customer]);

    $stats = $component->instance()->stats();

    expect((float) $stats['balance'])->toBe(60.0)
        ->and((float) $stats['on_account'])->toBe(20.0)
        ->and((float) $stats['sales_ytd'])->toBe(100.0);
});

test('customer show page exposes previous and next customer for navigation', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $first = Customer::factory()->create();
    $middle = Customer::factory()->create();
    $last = Customer::factory()->create();

    $component = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $middle]);

    expect($component->instance()->previousCustomer()->id)->toBe($first->id)
        ->and($component->instance()->nextCustomer()->id)->toBe($last->id);

    $firstComponent = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $first]);
    expect($firstComponent->instance()->previousCustomer())->toBeNull();

    $lastComponent = Livewire::actingAs($user)->test('pages::customers.show', ['customer' => $last]);
    expect($lastComponent->instance()->nextCustomer())->toBeNull();
});
