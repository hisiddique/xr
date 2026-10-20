<?php

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('dn_prefix', 'DN');
    Setting::set('inv_prefix', 'INV');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

test('cloning a delivery note creates a new active delivery note and opens it for edit', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $source = Document::factory()->deliveryNote()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory()->count(2), 'items')
        ->create(['status' => DocumentStatus::Converted]);

    $response = $this->actingAs($user)->post(route('delivery-notes.clone', $source));

    $clone = Document::deliveryNotes()->where('id', '!=', $source->id)->sole();

    $response->assertRedirect(route('delivery-notes.edit', $clone));
    expect($clone->doc_number)->not->toBe($source->doc_number)
        ->and($clone->status)->toBe(DocumentStatus::Active)
        ->and($clone->customer_id)->toBe($source->customer_id)
        ->and($clone->items)->toHaveCount(2)
        ->and(Document::invoices()->count())->toBe(0);
});

test('cloning an invoice picks a sequence free in both the delivery note and invoice series', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->deliveryNote()->for($customer, 'customer')->create(['doc_number' => 'DN-0061']);
    Document::factory()->invoice()->for($customer, 'customer')->create(['doc_number' => 'INV-0062']);
    $source = Document::factory()->invoice()->for($customer, 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create(['doc_number' => 'INV-0097']);

    $this->actingAs($user)->post(route('invoices.clone', $source));

    expect(Document::invoices()->pluck('doc_number')->all())->toContain('INV-0098')
        ->and(Document::deliveryNotes()->pluck('doc_number')->all())->toContain('DN-0098');
});

test('cloning an invoice is blocked when invoice cloning is disabled', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $source = Document::factory()->invoice()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create();
    Setting::set('clone_invoices_enabled', '0', 'boolean');

    $response = $this->actingAs($user)->post(route('invoices.clone', $source));

    $response->assertSessionHas('error', 'Cloning invoices is disabled.');
    expect(Document::invoices()->count())->toBe(1)
        ->and(Document::deliveryNotes()->count())->toBe(0);
});

test('cloning a delivery note is blocked when delivery note cloning is disabled', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $source = Document::factory()->deliveryNote()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create();
    Setting::set('clone_delivery_notes_enabled', '0', 'boolean');

    $response = $this->actingAs($user)->post(route('delivery-notes.clone', $source));

    $response->assertSessionHas('error', 'Cloning delivery notes is disabled.');
    expect(Document::deliveryNotes()->count())->toBe(1);
});

test('cloning a delivery note flashes the clone message and source number', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $source = Document::factory()->deliveryNote()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create();

    $response = $this->actingAs($user)->post(route('delivery-notes.clone', $source));

    $clone = Document::deliveryNotes()->where('id', '!=', $source->id)->sole();

    $response->assertSessionHas('success', "Delivery note {$clone->doc_number} cloned from {$source->doc_number}.")
        ->assertSessionHas('cloned_from', $source->doc_number);
});

test('cloning an invoice flashes the clone message and source number', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $source = Document::factory()->invoice()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory(), 'items')
        ->create();

    $response = $this->actingAs($user)->post(route('invoices.clone', $source));

    $clone = Document::invoices()->where('id', '!=', $source->id)->sole();

    $response->assertSessionHas('success', "Invoice {$clone->doc_number} cloned from {$source->doc_number}.")
        ->assertSessionHas('cloned_from', $source->doc_number);
});

test('cloning an invoice creates a paired delivery note and a new invoice with the same sequence', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $source = Document::factory()->invoice()
        ->for(Customer::factory()->create(), 'customer')
        ->has(DocumentItem::factory()->count(2), 'items')
        ->create();

    $response = $this->actingAs($user)->post(route('invoices.clone', $source));

    $clone = Document::invoices()->where('id', '!=', $source->id)->sole();
    $deliveryNote = Document::deliveryNotes()->sole();

    $response->assertRedirect(route('invoices.edit', $clone));
    expect($clone->converted_from_id)->toBe($deliveryNote->id)
        ->and($clone->status)->toBe(DocumentStatus::Active)
        ->and($deliveryNote->status)->toBe(DocumentStatus::Converted)
        ->and($clone->items)->toHaveCount(2)
        ->and($deliveryNote->items)->toHaveCount(2)
        ->and(substr($clone->doc_number, -4))->toBe(substr($deliveryNote->doc_number, -4))
        ->and($clone->type)->toBe(DocumentType::Invoice);
});
