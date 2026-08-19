<?php

use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushCache();
    Setting::set('vat_rate', '20', 'float');
});

it('shows payments allocated to an invoice', function () {
    $user = User::factory()->create();
    $invoice = Document::factory()->invoice()->create();
    $payment = Payment::factory()->for($invoice->customer)->create(['amount' => 100]);

    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 50,
    ]);

    $this->actingAs($user)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertSee($payment->reference)
        ->assertSee('50.00');
});

it('shows an empty state when no payments are allocated', function () {
    $user = User::factory()->create();
    $invoice = Document::factory()->invoice()->create();

    $this->actingAs($user)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertSee('No payments recorded');
});
