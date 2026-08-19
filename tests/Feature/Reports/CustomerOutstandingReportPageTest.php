<?php

use App\Jobs\SendCustomerOutstandingReportJob;
use App\Models\Customer;
use App\Models\Document;
use App\Models\ExportJob;
use App\Models\User;
use App\Models\WriteOff;
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

test('opening the write-off switch shows the modal with the amount pre-filled', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Settle Co']);
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 60,
        'doc_date' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->assertSeeText('Settle Co')
        ->call('openWriteOffModal', $invoice->id)
        ->assertSet('writeOffDocumentId', $invoice->id)
        ->assertSet('writeOffAmount', '60.00');

    expect(WriteOff::count())->toBe(0);
});

test('saving a write-off records reason, amount and datetime, and reduces the outstanding balance app-wide', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Settle Co']);
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 60,
        'doc_date' => now(),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->call('openWriteOffModal', $invoice->id)
        ->set('writeOffReason', 'Customer went into liquidation')
        ->set('writeOffAmount', '60.00')
        ->set('writeOffDateTime', '2026-01-15T10:30')
        ->call('saveWriteOff')
        ->assertDontSeeText('Settle Co');

    $writeOff = WriteOff::where('document_id', $invoice->id)->first();
    expect($writeOff)->not->toBeNull()
        ->and($writeOff->reason)->toBe('Customer went into liquidation')
        ->and((float) $writeOff->amount)->toBe(60.0)
        ->and($writeOff->written_off_at->format('Y-m-d H:i'))->toBe('2026-01-15 10:30')
        ->and($writeOff->written_off_by)->toBe($user->id);

    $component->set('showPaid', true)->assertSeeText('Settle Co');
});

test('write-off requires a reason', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $invoice = Document::factory()->invoice()->create(['total_value' => 60, 'doc_date' => now()]);

    Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->call('openWriteOffModal', $invoice->id)
        ->set('writeOffReason', '')
        ->call('saveWriteOff')
        ->assertHasErrors(['writeOffReason' => 'required']);

    expect(WriteOff::count())->toBe(0);
});

test('the undo action removes an existing write-off', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Settle Co']);
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 60,
        'doc_date' => now(),
    ]);

    WriteOff::create([
        'document_id' => $invoice->id, 'amount' => 60, 'reason' => 'Bad debt',
        'written_off_at' => now(), 'written_off_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::reports.customer-outstanding-payments')
        ->set('showPaid', true)
        ->assertSeeText('Settle Co')
        ->call('removeWriteOff', $invoice->id)
        // The invoice still has a real outstanding balance once the write-off is
        // undone, so it remains visible (now via the "outstanding" branch, not "written off").
        ->assertSeeText('Settle Co');

    expect(WriteOff::withTrashed()->where('document_id', $invoice->id)->first()->trashed())->toBeTrue();
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
