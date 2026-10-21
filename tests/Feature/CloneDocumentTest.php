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

function cloneAdmin(): User
{
    return User::factory()->admin()->create(['email_verified_at' => now()]);
}

test('opening the delivery note clone page prefills the form and saves nothing', function () {
    $source = Document::factory()->deliveryNote()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory()->count(2), 'items')
        ->create(['order_no' => 'PO-77']);

    Livewire::actingAs(cloneAdmin())
        ->test('pages::delivery-notes.create', ['document' => $source])
        ->assertSet('clonedFrom', $source->doc_number)
        ->assertSet('customer_id', $source->customer_id)
        ->assertSet('order_no', 'PO-77')
        ->assertCount('items', 2);

    expect(Document::count())->toBe(1);
});

test('submitting the delivery note clone creates a new active delivery note', function () {
    $source = Document::factory()->deliveryNote()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory()->count(2)->state(['per' => 'Each']), 'items')
        ->create(['doc_number' => 'DN-0097']);

    Livewire::actingAs(cloneAdmin())
        ->test('pages::delivery-notes.create', ['document' => $source])
        ->call('finalize', 'holdonly', false)
        ->assertHasNoErrors();

    $clone = Document::deliveryNotes()->where('id', '!=', $source->id)->sole();

    expect($clone->doc_number)->toBe('DN-0098')
        ->and($clone->status)->toBe(DocumentStatus::Active)
        ->and($clone->customer_id)->toBe($source->customer_id)
        ->and($clone->items)->toHaveCount(2)
        ->and(Document::invoices()->count())->toBe(0);
});

test('opening the invoice clone page saves nothing', function () {
    $source = Document::factory()->invoice()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory()->count(2), 'items')
        ->create();

    $this->actingAs(cloneAdmin())
        ->get(route('invoices.clone', $source))
        ->assertOk()
        ->assertSee('Clone: '.$source->doc_number);

    expect(Document::count())->toBe(1);
});

test('submitting the invoice clone creates a paired delivery note and invoice with one shared sequence', function () {
    $customer = Customer::factory()->create();
    Document::factory()->deliveryNote()->for($customer, 'customer')->create(['doc_number' => 'DN-0061']);
    Document::factory()->invoice()->for($customer, 'customer')->create(['doc_number' => 'INV-0062']);
    $source = Document::factory()->invoice()->for($customer, 'customer')
        ->has(DocumentItem::factory()->count(2), 'items')
        ->create(['doc_number' => 'INV-0097']);

    Livewire::actingAs(cloneAdmin())
        ->test('pages::invoices.clone', ['document' => $source])
        ->call('save')
        ->assertHasNoErrors();

    $invoice = Document::invoices()->where('id', '!=', $source->id)->where('doc_number', '!=', 'INV-0062')->sole();
    $deliveryNote = Document::deliveryNotes()->where('doc_number', 'DN-0098')->sole();

    expect($invoice->doc_number)->toBe('INV-0098')
        ->and($invoice->converted_from_id)->toBe($deliveryNote->id)
        ->and($invoice->status)->toBe(DocumentStatus::Active)
        ->and($deliveryNote->status)->toBe(DocumentStatus::Converted)
        ->and($invoice->items)->toHaveCount(2)
        ->and($deliveryNote->items)->toHaveCount(2)
        ->and($invoice->type)->toBe(DocumentType::Invoice);
});

test('clone pages are blocked when cloning is disabled', function () {
    Setting::set('clone_invoices_enabled', '0', 'boolean');
    Setting::set('clone_delivery_notes_enabled', '0', 'boolean');
    $customer = Customer::factory()->create();
    $deliveryNote = Document::factory()->deliveryNote()->for($customer, 'customer')->create();
    $invoice = Document::factory()->invoice()->for($customer, 'customer')->create();
    $admin = cloneAdmin();

    $this->actingAs($admin)->get(route('delivery-notes.clone', $deliveryNote))->assertForbidden();
    $this->actingAs($admin)->get(route('invoices.clone', $invoice))->assertForbidden();
});
