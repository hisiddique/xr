<?php

use App\Mail\CustomerStatementMail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('sending a statement requires at least one recipient', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::customers.statement-modal', ['customer' => $customer])
        ->set('action', 'email')
        ->set('statementEmails', [])
        ->call('generate')
        ->assertHasErrors(['statementEmails']);
});

test('sending a statement emails the filtered invoices as a PDF attachment', function () {
    Mail::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'doc_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::customers.statement-modal', ['customer' => $customer])
        ->set('action', 'email')
        ->set('statementEmails', ['ops@example.com'])
        ->call('generate')
        ->assertHasNoErrors();

    Mail::assertSent(CustomerStatementMail::class, function (CustomerStatementMail $mail) {
        return $mail->hasTo('ops@example.com') && count($mail->attachments()) === 1;
    });
});
