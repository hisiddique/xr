<?php

use App\Jobs\SendCustomerOutstandingReportJob;
use App\Models\Customer;
use App\Models\Document;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('report page is accessible and lists outstanding invoices grouped by customer', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Outstanding Co']);
    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 75,
        'doc_date' => now(),
    ]);

    $this->actingAs($user)->get(route('reports.customer-outstanding-payments'))->assertOk();

    Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->assertSeeText('Outstanding Co')
        ->assertSeeText('75.00');
});

test('report page search filters by customer name', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $matching = Customer::factory()->create(['company_name' => 'Zeta Traders']);
    $other = Customer::factory()->create(['company_name' => 'Omega Supplies']);
    Document::factory()->invoice()->create(['customer_id' => $matching->id, 'total_value' => 30, 'doc_date' => now()]);
    Document::factory()->invoice()->create(['customer_id' => $other->id, 'total_value' => 40, 'doc_date' => now()]);

    Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->set('search', 'Zeta')
        ->assertSeeText('Zeta Traders')
        ->assertDontSeeText('Omega Supplies');
});

test('toggling settled hides the invoice by default and reveals it with showPaid', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Settle Co']);
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 60,
        'doc_date' => now(),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->assertSeeText('Settle Co')
        ->call('toggleSettled', $invoice->id)
        ->assertDontSeeText('Settle Co');

    expect($invoice->refresh()->is_settled)->toBeTrue();

    $component->set('showPaid', true)
        ->assertSeeText('Settle Co');

    $component->call('toggleSettled', $invoice->id)
        ->assertSeeText('Settle Co');

    expect($invoice->refresh()->is_settled)->toBeFalse();
});

test('sending the report requires at least one recipient and at least one format', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 20, 'doc_date' => now()]);

    Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->set('reportEmails', [])
        ->set('reportFormats', [])
        ->call('sendReportEmail')
        ->assertHasErrors(['reportEmails', 'reportFormats']);
});

test('sending the report queues a job instead of sending inline', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 20, 'doc_date' => now()]);

    Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->set('reportEmails', ['ops@example.com'])
        ->set('reportFormats', ['pdf', 'csv'])
        ->call('sendReportEmail')
        ->assertHasNoErrors();

    expect(ExportJob::query()->where('format', 'email')->where('created_by', $user->id)->exists())->toBeTrue();

    Queue::assertPushed(SendCustomerOutstandingReportJob::class);
});
