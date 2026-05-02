<?php

use App\DocumentStatus;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('inv_prefix', 'INV');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

test('invoice can be soft deleted via the delete-modal', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    $invoice = Document::factory()->invoice()->create(['customer_id' => $customer->id]);

    Livewire::actingAs($user)
        ->test('pages::invoices.delete-modal', ['document' => $invoice])
        ->call('deleteDocument');

    $this->assertSoftDeleted('documents', ['id' => $invoice->id]);
});

test('deleting an invoice reverts the source delivery note status to Active', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'status' => DocumentStatus::Converted,
    ]);
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'converted_from_id' => $dn->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::invoices.delete-modal', ['document' => $invoice])
        ->call('deleteDocument');

    expect($dn->fresh()->status)->toBe(DocumentStatus::Active);
});

test('restoring an invoice from the index brings back the source DN to Converted', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'status' => DocumentStatus::Active,
    ]);
    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'converted_from_id' => $dn->id,
    ]);
    $invoice->delete();

    Livewire::actingAs($user)
        ->test('pages::invoices.index')
        ->call('restore', $invoice->id);

    expect(Document::find($invoice->id))->not->toBeNull()
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('trashed filter only shows soft-deleted invoices', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $active = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-2026-0900',
    ]);
    $trashed = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-2026-0901',
    ]);
    $trashed->delete();

    Livewire::actingAs($user)->test('pages::invoices.index')
        ->set('trashed', true)
        ->assertSee('INV-2026-0901')
        ->assertDontSee('INV-2026-0900');
});
