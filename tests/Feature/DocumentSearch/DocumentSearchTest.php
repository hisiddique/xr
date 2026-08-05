<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('document search page is accessible', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);

    $this->actingAs($user)->get(route('document-search.index'))->assertOk();
});

test('search matches item details on delivery notes and invoices', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $dn = Document::factory()->deliveryNote()->create();
    $inv = Document::factory()->invoice()->create();
    DocumentItem::factory()->create(['document_id' => $dn->id, 'details' => 'Blue Widget', 'is_note' => false]);
    DocumentItem::factory()->create(['document_id' => $inv->id, 'details' => 'Blue Widget Pro', 'is_note' => false]);
    DocumentItem::factory()->create(['document_id' => $dn->id, 'details' => 'Red Gadget', 'is_note' => false]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('search', 'Blue Widget')
        ->assertSee($dn->doc_number)
        ->assertSee($inv->doc_number)
        ->assertDontSee('Red Gadget');
});

test('search excludes note rows and credit note documents', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $dn = Document::factory()->deliveryNote()->create();
    $cn = Document::factory()->creditNote()->create();
    DocumentItem::factory()->create(['document_id' => $dn->id, 'details' => 'Special Instructions', 'is_note' => true]);
    DocumentItem::factory()->create(['document_id' => $cn->id, 'details' => 'Special Refund Item', 'is_note' => false]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('search', 'Special')
        ->assertDontSee('Special Instructions')
        ->assertDontSee('Special Refund Item');
});

test('toggling a result row adds and removes its document from the bottom panel', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $dn = Document::factory()->deliveryNote()->create();
    DocumentItem::factory()->create(['document_id' => $dn->id, 'details' => 'Green Fastener', 'is_note' => false]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('search', 'Green Fastener')
        ->call('toggleDocument', $dn->id)
        ->assertSet('selectedDocumentIds', [$dn->id])
        ->call('toggleDocument', $dn->id)
        ->assertSet('selectedDocumentIds', []);
});

test('customer filter narrows results to that customer without a search term', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customerA = Customer::factory()->create();
    $customerB = Customer::factory()->create();
    $docA = Document::factory()->deliveryNote()->create(['customer_id' => $customerA->id]);
    $docB = Document::factory()->deliveryNote()->create(['customer_id' => $customerB->id]);
    DocumentItem::factory()->create(['document_id' => $docA->id, 'details' => 'Item From A', 'is_note' => false]);
    DocumentItem::factory()->create(['document_id' => $docB->id, 'details' => 'Item From B', 'is_note' => false]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('customerId', $customerA->id)
        ->assertSee($docA->doc_number)
        ->assertDontSee($docB->doc_number);
});

test('invoice picker searches the server by doc number and supports selecting multiple invoices', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    $inv1 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'doc_number' => 'INV-2024-0001']);
    $inv2 = Document::factory()->invoice()->create(['customer_id' => $customer->id, 'doc_number' => 'INV-2024-0002']);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('customerId', $customer->id)
        ->set('invoiceSearch', '0001')
        ->assertSee('INV-2024-0001')
        ->assertDontSee('INV-2024-0002')
        ->call('addInvoice', $inv1->id)
        ->assertSet('invoiceIds', [$inv1->id])
        ->assertSet('invoiceSearch', '')
        ->set('invoiceSearch', '0002')
        ->call('addInvoice', $inv2->id)
        ->assertSet('invoiceIds', [$inv1->id, $inv2->id])
        ->assertSee('INV-2024-0001') // still visible as a selected chip
        ->assertSee('INV-2024-0002');
});

test('addInvoice does not duplicate an already-selected invoice', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    $inv = Document::factory()->invoice()->create(['customer_id' => $customer->id]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('customerId', $customer->id)
        ->call('addInvoice', $inv->id)
        ->call('addInvoice', $inv->id)
        ->assertSet('invoiceIds', [$inv->id]);
});

test('removeInvoice drops a single invoice from the selection', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    $inv1 = Document::factory()->invoice()->create(['customer_id' => $customer->id]);
    $inv2 = Document::factory()->invoice()->create(['customer_id' => $customer->id]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('customerId', $customer->id)
        ->set('invoiceIds', [$inv1->id, $inv2->id])
        ->call('removeInvoice', $inv1->id)
        ->assertSet('invoiceIds', [$inv2->id]);
});

test('invoice filter narrows results to the selected invoices', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    $inv1 = Document::factory()->invoice()->create(['customer_id' => $customer->id]);
    $inv2 = Document::factory()->invoice()->create(['customer_id' => $customer->id]);
    DocumentItem::factory()->create(['document_id' => $inv1->id, 'details' => 'Item From Inv1', 'is_note' => false]);
    DocumentItem::factory()->create(['document_id' => $inv2->id, 'details' => 'Item From Inv2', 'is_note' => false]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('customerId', $customer->id)
        ->set('invoiceIds', [$inv1->id])
        ->assertSee('Item From Inv1')
        ->assertDontSee('Item From Inv2');
});

test('date range filter narrows results by document date', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $inOld = Document::factory()->deliveryNote()->create(['doc_date' => '2024-01-01']);
    $inRange = Document::factory()->deliveryNote()->create(['doc_date' => '2024-06-15']);
    DocumentItem::factory()->create(['document_id' => $inOld->id, 'details' => 'Old Item', 'is_note' => false]);
    DocumentItem::factory()->create(['document_id' => $inRange->id, 'details' => 'Range Item', 'is_note' => false]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('dateFrom', '2024-06-01')
        ->set('dateTo', '2024-06-30')
        ->assertSee($inRange->doc_number)
        ->assertDontSee($inOld->doc_number);
});

test('clearFilters resets all filters and search', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('search', 'something')
        ->set('customerId', $customer->id)
        ->set('invoiceIds', [1])
        ->set('dateFrom', '2024-01-01')
        ->set('dateTo', '2024-12-31')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertSet('customerId', null)
        ->assertSet('invoiceIds', [])
        ->assertSet('dateFrom', null)
        ->assertSet('dateTo', null);
});

test('collected documents panel persists across a new search', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $dn1 = Document::factory()->deliveryNote()->create();
    $dn2 = Document::factory()->deliveryNote()->create();
    DocumentItem::factory()->create(['document_id' => $dn1->id, 'details' => 'Alpha Item', 'is_note' => false]);
    DocumentItem::factory()->create(['document_id' => $dn2->id, 'details' => 'Beta Item', 'is_note' => false]);

    Livewire::actingAs($user)
        ->test('pages::document-search.index')
        ->set('search', 'Alpha Item')
        ->call('toggleDocument', $dn1->id)
        ->set('search', 'Beta Item')
        ->assertSet('selectedDocumentIds', [$dn1->id])
        ->call('toggleDocument', $dn2->id)
        ->assertSet('selectedDocumentIds', [$dn2->id, $dn1->id]);
});
