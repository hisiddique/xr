<?php

use App\Models\Customer;
use App\Models\Document;
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
