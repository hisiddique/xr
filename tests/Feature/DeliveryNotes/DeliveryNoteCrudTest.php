<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('dn_prefix', 'DN');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

test('delivery note index page is accessible', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route('delivery-notes.index'))->assertOk();
});

test('delivery note can be created with multiple line items', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', '2026-04-13')
        ->set('items', [
            ['details' => 'Item 1', 'quantity' => '2', 'per' => 'each'],
            ['details' => 'Item 2', 'quantity' => '1', 'per' => ''],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('dn-open-finish')
        ->call('finalize', 'holdonly', false)
        ->assertRedirect(route('delivery-notes.index'));

    $doc = Document::first();
    expect($doc->doc_number)->toMatch('/^DN-\d{4}$/')
        ->and($doc->items->count())->toBe(2)
        ->and((float) $doc->subtotal)->toBe(0.0)
        ->and((float) $doc->vat_amount)->toBe(0.0)
        ->and((float) $doc->total_value)->toBe(0.0);
});

test('delivery note number follows DN-0001 format', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['details' => 'Test', 'quantity' => '1', 'per' => ''],
        ])
        ->call('save')
        ->call('finalize', 'holdonly', false);

    expect(Document::first()->doc_number)->toBe('DN-0001');
});

test('rejecting entries creates no delivery note', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [['details' => 'Item 1', 'quantity' => '2', 'per' => '']])
        ->call('finalize', 'reject', false)
        ->assertRedirect(route('delivery-notes.index'));

    expect(Document::count())->toBe(0);
});

test('print action creates the note and redirects to its page with the print flag', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    $component = Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [['details' => 'Item 1', 'quantity' => '2', 'per' => '']])
        ->call('save')
        ->call('finalize', 'print', false)
        ->assertHasNoErrors();

    $document = Document::firstOrFail();
    expect(Document::count())->toBe(1);
    $component->assertRedirect(route('delivery-notes.show', ['document' => $document, 'do' => 'print']));
});

test('delivery note requires at least one item', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [])
        ->call('save')
        ->assertHasErrors(['items']);
});

test('delivery note requires customer', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', 0)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [['details' => 'Test', 'quantity' => '1', 'per' => '']])
        ->call('save')
        ->assertHasErrors(['customer_id']);
});

test('delivery note can be soft deleted', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    $document = Document::factory()->deliveryNote()->create(['customer_id' => $customer->id]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.delete-modal', ['document' => $document])
        ->call('deleteDocument');

    $this->assertSoftDeleted('documents', ['id' => $document->id]);
});

test('delivery note saves prices and computes totals when show_pricing is on', function () {
    Setting::set('vat_rate', '20', 'integer');
    Setting::flushCache();

    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', '2026-04-13')
        ->set('show_pricing', true)
        ->set('items', [
            ['details' => 'Widget', 'quantity' => '2', 'price' => '50', 'per' => 'each', 'is_note' => false],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('dn-open-finish')
        ->call('finalize', 'holdonly', true)
        ->assertRedirect(route('delivery-notes.index'));

    $doc = Document::first();
    expect((bool) $doc->show_pricing)->toBeTrue()
        ->and((float) $doc->subtotal)->toBe(100.0)
        ->and((float) $doc->vat_amount)->toBe(20.0)
        ->and((float) $doc->total_value)->toBe(120.0)
        ->and((float) $doc->items->first()->price)->toBe(50.0)
        ->and((float) $doc->items->first()->line_value)->toBe(100.0);
});

test('delivery note can be edited', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);
    $document = Document::factory()->deliveryNote()->create(['customer_id' => $customer->id, 'doc_number' => 'DN-2026-0001']);
    $document->items()->create(['details' => 'Old item', 'quantity' => 1, 'price' => 0, 'line_value' => 0]);

    Livewire::actingAs($user)
        ->test('pages::delivery-notes.edit', ['document' => $document])
        ->set('items', [
            ['details' => 'New item', 'quantity' => '2', 'per' => 'each'],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $document->refresh();
    expect($document->items->count())->toBe(1)
        ->and($document->items->first()->details)->toBe('New item');
});
