<?php

use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushCache();
    Setting::set('vat_rate', '20', 'float');
});

it('resolves the invoices.index named route', function () {
    expect(route('invoices.index'))->toContain('/invoices');
});

it('resolves the invoices.edit named route for a document', function () {
    $inv = Document::factory()->invoice()->create();
    expect(route('invoices.edit', $inv))->toContain("/invoices/{$inv->id}/edit");
});

it('resolves the invoices.show named route for a document', function () {
    $inv = Document::factory()->invoice()->create();
    expect(route('invoices.show', $inv))->toContain("/invoices/{$inv->id}");
});

it('resolves the delivery-notes.convert named route', function () {
    $dn = Document::factory()->deliveryNote()->create();
    expect(route('delivery-notes.convert', $dn))->toContain("/delivery-notes/{$dn->id}/convert");
});

it('resolves the documents.pdf named route', function () {
    $doc = Document::factory()->invoice()->create();
    expect(route('documents.pdf', $doc))->toContain("/documents/{$doc->id}/pdf");
});

it('resolves the documents.pdf.download named route', function () {
    $doc = Document::factory()->invoice()->create();
    expect(route('documents.pdf.download', $doc))->toContain("/documents/{$doc->id}/pdf/download");
});
