<?php

use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('payment reference is shown on the show page', function () {
    $payment = Payment::factory()->create(['payment_reference' => 'CHQ-00123']);

    Livewire::test('pages::payments.show', ['payment' => $payment])
        ->assertSee('CHQ-00123');
});

test('payment reference is shown on the index page', function () {
    $payment = Payment::factory()->create(['payment_reference' => 'CHQ-00456']);

    Livewire::test('pages::payments.index')
        ->assertSee('CHQ-00456');
});

test('payments index search matches by payment reference', function () {
    $method = LookupPaymentMethod::factory()->create();
    $match = Payment::factory()->create(['payment_method_id' => $method->id, 'payment_reference' => 'CHQ-99999']);
    $other = Payment::factory()->create(['payment_method_id' => $method->id, 'payment_reference' => 'CHQ-11111']);

    Livewire::test('pages::payments.index')
        ->set('search', 'CHQ-99999')
        ->assertSee($match->reference)
        ->assertDontSee($other->reference);
});
