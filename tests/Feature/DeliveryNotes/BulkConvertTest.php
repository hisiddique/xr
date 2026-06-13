<?php

use App\DocumentStatus;
use App\DocumentType;
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
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

test('bulkConvert turns each selected active DN into its own invoice', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $dn1 = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create(['status' => DocumentStatus::Active]);
    $dn2 = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create(['status' => DocumentStatus::Active]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.index')
        ->set('selectedIds', [$dn1->id, $dn2->id])
        ->call('bulkConvert');

    expect(Document::where('type', DocumentType::Invoice)->count())->toBe(2)
        ->and($dn1->fresh()->status)->toBe(DocumentStatus::Converted)
        ->and($dn2->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('bulkConvert skips already-converted DNs and converts the rest', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $active = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create(['status' => DocumentStatus::Active]);
    $alreadyConverted = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create(['status' => DocumentStatus::Converted]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.index')
        ->set('selectedIds', [$active->id, $alreadyConverted->id])
        ->call('bulkConvert');

    expect(Document::where('type', DocumentType::Invoice)->count())->toBe(1)
        ->and($active->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('bulkConvert clears the selection after running', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    $dn = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create(['status' => DocumentStatus::Active]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.index')
        ->set('selectedIds', [$dn->id])
        ->call('bulkConvert')
        ->assertSet('selectedIds', []);
});

test('convertSingle converts and opens the success modal without redirecting', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create(['status' => DocumentStatus::Active]);

    $component = Livewire::actingAs($user)
        ->test('pages::delivery-notes.index')
        ->set('convertingNoteId', $dn->id)
        ->call('convertSingle')
        ->assertNoRedirect()
        ->assertDispatched('conversion-succeeded');

    $invoice = Document::where('type', DocumentType::Invoice)
        ->where('converted_from_id', $dn->id)
        ->firstOrFail();

    $component->assertSet('convertedInvoiceId', $invoice->id)
        ->assertSet('convertedInvoiceNumber', $invoice->doc_number);

    expect($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('bulkConvert ignores invoice ids that sneak into the selection', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $invoice = Document::factory()->invoice()
        ->for($customer, 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create(['status' => DocumentStatus::Active]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.index')
        ->set('selectedIds', [$invoice->id])
        ->call('bulkConvert');

    expect(Document::where('type', DocumentType::Invoice)->count())->toBe(1);
});
