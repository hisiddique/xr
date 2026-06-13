<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('dn_prefix', 'DN');
    Setting::set('inv_prefix', 'INV');
    Setting::set('cn_prefix', 'CN');
    Setting::set('number_padding', '4', 'integer');
    Setting::set('vat_rate', '20', 'float');
    Setting::flushCache();
});

it('credit note create errors when price set without per', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::credit-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['id' => null, 'details' => 'Widget A', 'is_note' => false, 'quantity' => '2', 'price' => '50', 'per' => ''],
        ])
        ->call('save')
        ->assertHasErrors(['items.0.per']);
});

it('credit note create errors when per set without price', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::credit-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['id' => null, 'details' => 'Widget A', 'is_note' => false, 'quantity' => '2', 'price' => '0', 'per' => 'each'],
        ])
        ->call('save')
        ->assertHasErrors(['items.0.price']);
});

it('credit note create passes when price and per set together', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::credit-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['id' => null, 'details' => 'Widget A', 'is_note' => false, 'quantity' => '2', 'price' => '50', 'per' => 'each'],
        ])
        ->call('save')
        ->assertHasNoErrors();
});

it('credit note create allows zero price when no per', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::credit-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['id' => null, 'details' => 'Widget A', 'is_note' => false, 'quantity' => '2', 'price' => '0', 'per' => ''],
        ])
        ->call('save')
        ->assertHasNoErrors();
});

it('invoice edit errors when price set without per', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    $invoice = Document::factory()->invoice()->for($customer)->create();
    DocumentItem::factory()->create(['document_id' => $invoice->id, 'is_note' => false]);

    Livewire::actingAs($user)
        ->test('pages::invoices.edit', ['document' => $invoice])
        ->set('items', [
            ['id' => null, 'details' => 'Widget A', 'is_note' => false, 'quantity' => '2', 'price' => '50', 'per' => ''],
        ])
        ->call('save')
        ->assertHasErrors(['items.0.per']);
});

it('delivery note create errors when per set without price', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['details' => 'Widget A', 'is_note' => false, 'quantity' => '2', 'price' => '', 'per' => 'each'],
        ])
        ->call('finalize', 'holdonly', false)
        ->assertHasErrors(['items.0.price']);
});

it('delivery note create errors when price set without per', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['details' => 'Widget A', 'is_note' => false, 'quantity' => '2', 'price' => '50', 'per' => ''],
        ])
        ->call('finalize', 'holdonly', false)
        ->assertHasErrors(['items.0.per']);
});

it('delivery note create accepts a price-less line', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['details' => 'Widget A', 'is_note' => false, 'quantity' => '2', 'price' => '', 'per' => ''],
        ])
        ->call('finalize', 'holdonly', false)
        ->assertHasNoErrors();
});
