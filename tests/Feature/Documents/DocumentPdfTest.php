<?php

use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::flushCache();
    Setting::set('company_name', 'Test Co', 'string');
    Setting::set('company_address', '1 Test Street', 'string');
    Setting::set('company_email', 'info@testco.com', 'string');
    Setting::set('vat_rate', '20', 'float');
});

it('returns an application/pdf response for the stream route', function () {
    $user = User::factory()->create();
    $document = Document::factory()
        ->invoice()
        ->has(DocumentItem::factory()->count(2), 'items')
        ->create();

    $response = $this->actingAs($user)->get(route('documents.pdf', $document));

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect(strlen($response->getContent()))->toBeGreaterThan(0);
});

it('returns Content-Disposition attachment for the download route', function () {
    $user = User::factory()->create();
    $document = Document::factory()
        ->invoice()
        ->has(DocumentItem::factory()->count(1), 'items')
        ->create(['doc_number' => 'INV-2026-0001']);

    $response = $this->actingAs($user)->get(route('documents.pdf.download', $document));

    $response->assertStatus(200);
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->headers->get('Content-Disposition'))->toContain('INV-2026-0001.pdf');
});

it('redirects unauthenticated users away from the pdf route', function () {
    $document = Document::factory()->invoice()->has(DocumentItem::factory(), 'items')->create();

    $this->get(route('documents.pdf', $document))->assertRedirect();
});
