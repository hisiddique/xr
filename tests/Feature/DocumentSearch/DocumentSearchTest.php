<?php

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
