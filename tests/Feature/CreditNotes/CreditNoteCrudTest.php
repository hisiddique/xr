<?php

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\DocumentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('dn_prefix', 'DN');
    Setting::set('inv_prefix', 'INV');
    Setting::set('cn_prefix', 'CN');
    Setting::set('number_padding', '4', 'integer');
    Setting::set('vat_rate', '20', 'float');
    Setting::flushCache();
});

test('credit note index page is accessible', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route('credit-notes.index'))->assertOk();
});

test('credit note can be created as a standalone with priced line items', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::credit-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', '2026-06-11')
        ->set('items', [
            ['id' => null, 'details' => 'Widget A', 'quantity' => '2', 'price' => '50', 'per' => 'each', 'is_note' => false, 'discount_percent' => 10],
        ])
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $doc = Document::first();
    expect($doc->type)->toBe(DocumentType::CreditNote)
        ->and($doc->doc_number)->toBe('CN-0001')
        ->and($doc->status)->toBe(DocumentStatus::Active)
        ->and($doc->credited_invoice_id)->toBeNull()
        ->and((float) $doc->total_value)->toBeGreaterThan(0);
});

test('create linked to an invoice saves credited_invoice_id and total', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    $invoice = Document::factory()->invoice()->for($customer)->create();
    $invoiceItem = DocumentItem::factory()->create([
        'document_id' => $invoice->id,
        'is_note' => false,
        'quantity' => 2,
        'price' => 50,
        'line_value' => 100,
    ]);

    Livewire::actingAs($user)
        ->test('pages::credit-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', '2026-06-11')
        ->set('credited_invoice_id', $invoice->id)
        ->set('items', [
            [
                'id' => null,
                'details' => $invoiceItem->details,
                'quantity' => (string) $invoiceItem->quantity,
                'price' => (string) $invoiceItem->price,
                'per' => $invoiceItem->per ?? 'each',
                'is_note' => false,
                'discount_percent' => 50,
            ],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $cn = Document::where('type', DocumentType::CreditNote->value)->first();
    expect($cn->credited_invoice_id)->toBe($invoice->id)
        ->and((float) $cn->total_value)->toBeGreaterThan(0);
});

test('credit note can be edited and notes + item changes are persisted', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    $cn = Document::factory()->creditNote()->for($customer)->create();
    $cn->items()->create([
        'details' => 'Old item',
        'is_note' => false,
        'quantity' => 1,
        'price' => 10,
        'per' => 'each',
        'line_value' => 10,
    ]);

    Livewire::actingAs($user)
        ->test('pages::credit-notes.edit', ['document' => $cn])
        ->set('notes', 'Defective goods returned')
        ->set('items', [
            ['id' => null, 'details' => 'New item', 'is_note' => false, 'quantity' => '3', 'price' => '20', 'per' => 'each', 'discount_percent' => 0],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $cn->refresh();
    expect($cn->notes)->toBe('Defective goods returned')
        ->and($cn->items->count())->toBe(1)
        ->and($cn->items->first()->details)->toBe('New item');
});

test('credit note scope is isolated from invoices and delivery notes', function () {
    $customer = Customer::factory()->create();

    Document::factory()->creditNote()->for($customer)->create();
    Document::factory()->invoice()->for($customer)->create();
    Document::factory()->deliveryNote()->for($customer)->create();

    expect(Document::creditNotes()->count())->toBe(1)
        ->and(Document::invoices()->count())->toBe(1)
        ->and(Document::deliveryNotes()->count())->toBe(1);

    $cnIds = Document::creditNotes()->pluck('id');
    expect(Document::invoices()->whereIn('id', $cnIds)->count())->toBe(0)
        ->and(Document::deliveryNotes()->whereIn('id', $cnIds)->count())->toBe(0);
});

test('credit note PDF renders with application/pdf content type', function () {
    $user = User::factory()->create(['email_verified_at' => now()]);

    Setting::set('company_name', 'Test Co', 'string');
    Setting::set('company_address', '1 Test Street', 'string');
    Setting::set('company_email', 'info@testco.com', 'string');
    Setting::flushCache();

    $cn = Document::factory()
        ->creditNote()
        ->has(DocumentItem::factory()->count(1), 'items')
        ->create();

    $response = $this->actingAs($user)->get(route('documents.pdf', $cn));

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf')
        ->and(strlen($response->getContent()))->toBeGreaterThan(0);
});

test('first credit note gets number CN-0001', function () {
    $generator = new DocumentNumberGenerator;

    expect($generator->nextFor('CN'))->toBe('CN-0001');
});

test('credit note number increments independently of DN and INV sequences', function () {
    $customer = Customer::factory()->create();

    Document::factory()->deliveryNote()->create(['customer_id' => $customer->id, 'doc_number' => 'DN-0001']);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'doc_number' => 'INV-0001']);

    $generator = new DocumentNumberGenerator;
    expect($generator->nextFor('CN'))->toBe('CN-0001');
});

test('credit note saved via create page receives CN-0001 number', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['trade_discount' => 0]);

    Livewire::actingAs($user)
        ->test('pages::credit-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            ['id' => null, 'details' => 'Test item', 'quantity' => '1', 'price' => '10', 'per' => 'each', 'is_note' => false],
        ])
        ->call('save')
        ->assertHasNoErrors();

    expect(Document::where('type', DocumentType::CreditNote->value)->first()->doc_number)->toBe('CN-0001');
});

test('credit note show page renders with reason and credited-against link', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $invoice = Document::factory()->invoice()->for($customer)->create();
    $cn = Document::factory()->creditNote()->for($customer)->create([
        'credited_invoice_id' => $invoice->id,
        'notes' => 'Damaged goods',
    ]);
    DocumentItem::factory()->create(['document_id' => $cn->id, 'is_note' => false]);

    $this->actingAs($user)
        ->get(route('credit-notes.show', $cn))
        ->assertOk()
        ->assertSee($cn->doc_number)
        ->assertSee($invoice->doc_number)
        ->assertSee('Damaged goods');
});

test('invoice show page shows credited-by badge for linked credit notes', function () {
    $user = User::factory()->admin()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $invoice = Document::factory()->invoice()->for($customer)->create();
    $cn = Document::factory()->creditNote()->for($customer)->create([
        'credited_invoice_id' => $invoice->id,
    ]);

    $this->actingAs($user)
        ->get(route('invoices.show', $invoice))
        ->assertOk()
        ->assertSee($cn->doc_number);
});
