<?php

use App\Jobs\SendDocumentEmailJob;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentEmailLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('inv_prefix', 'INV');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

test('bulk emailing selected invoices sends to each customer email', function () {
    Mail::fake();

    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['email_1' => 'billing@example.com']);
    $invoiceOne = Document::factory()->invoice()->create(['customer_id' => $customer->id]);
    $invoiceTwo = Document::factory()->invoice()->create(['customer_id' => $customer->id]);

    Livewire::actingAs($user)
        ->test('pages::invoices.index')
        ->set('selectedIds', [$invoiceOne->id, $invoiceTwo->id])
        ->call('bulkEmail');

    expect(DocumentEmailLog::where('status', 'sent')->count())->toBe(2)
        ->and(DocumentEmailLog::where('status', 'failed')->count())->toBe(0);
});

test('bulk emailing 5 or more selected invoices queues jobs instead of sending inline', function () {
    Bus::fake();

    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['email_1' => 'billing@example.com']);
    $invoices = Document::factory()->invoice()->count(5)->create(['customer_id' => $customer->id]);

    Livewire::actingAs($user)
        ->test('pages::invoices.index')
        ->set('selectedIds', $invoices->pluck('id')->all())
        ->call('bulkEmail');

    Bus::assertDispatchedTimes(SendDocumentEmailJob::class, 5);
    expect(DocumentEmailLog::count())->toBe(0);
});
