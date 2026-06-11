<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\Setting;
use App\Services\DocumentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('dn_prefix', 'DN');
    Setting::set('inv_prefix', 'INV');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

test('first delivery note gets number DN-0001', function () {
    $generator = new DocumentNumberGenerator;

    expect($generator->nextFor('DN'))->toBe('DN-0001');
});

test('second delivery note gets incrementing number', function () {
    $customer = Customer::factory()->create();

    Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-0001',
    ]);

    $generator = new DocumentNumberGenerator;
    expect($generator->nextFor('DN'))->toBe('DN-0002');
});

test('invoice prefix is separate from delivery note sequence', function () {
    $customer = Customer::factory()->create();

    Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-0001',
    ]);

    $generator = new DocumentNumberGenerator;
    expect($generator->nextFor('INV'))->toBe('INV-0001');
});

test('number padding is applied correctly', function () {
    Setting::set('number_padding', '6', 'integer');
    Setting::flushCache();

    $generator = new DocumentNumberGenerator;

    expect($generator->nextFor('DN'))->toBe('DN-000001');
});

test('soft-deleted documents still occupy their number so it is not reused', function () {
    $customer = Customer::factory()->create();

    $doc = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-0001',
    ]);
    $doc->delete();

    $generator = new DocumentNumberGenerator;

    expect($generator->nextFor('DN'))->toBe('DN-0002');
});

test('documents from any year contribute to the global sequence', function () {
    $customer = Customer::factory()->create();
    $lastYear = now()->subYear()->year;

    Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => "DN-{$lastYear}-0050",
        'created_at' => now()->subYear(),
    ]);

    $generator = new DocumentNumberGenerator;

    expect($generator->nextFor('DN'))->toBe('DN-0051');
});
