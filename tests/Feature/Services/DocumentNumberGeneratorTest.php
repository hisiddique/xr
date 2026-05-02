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

test('first delivery note gets number DN-YYYY-0001', function () {
    $generator = new DocumentNumberGenerator;
    $year = now()->year;

    expect($generator->nextFor('DN'))->toBe("DN-{$year}-0001");
});

test('second delivery note gets incrementing number', function () {
    $customer = Customer::factory()->create();
    $year = now()->year;

    Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => "DN-{$year}-0001",
    ]);

    $generator = new DocumentNumberGenerator;
    expect($generator->nextFor('DN'))->toBe("DN-{$year}-0002");
});

test('invoice prefix is separate from delivery note sequence', function () {
    $customer = Customer::factory()->create();
    $year = now()->year;

    Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => "DN-{$year}-0001",
    ]);

    $generator = new DocumentNumberGenerator;
    expect($generator->nextFor('INV'))->toBe("INV-{$year}-0001");
});

test('number padding is applied correctly', function () {
    Setting::set('number_padding', '6', 'integer');
    Setting::flushCache();

    $generator = new DocumentNumberGenerator;
    $year = now()->year;

    expect($generator->nextFor('DN'))->toBe("DN-{$year}-000001");
});

test('soft-deleted documents still occupy their number so it is not reused', function () {
    $customer = Customer::factory()->create();
    $year = now()->year;

    $doc = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => "DN-{$year}-0001",
    ]);
    $doc->delete();

    $generator = new DocumentNumberGenerator;

    expect($generator->nextFor('DN'))->toBe("DN-{$year}-0002");
});

test('existing documents from previous year do not affect current year sequence', function () {
    $customer = Customer::factory()->create();
    $lastYear = now()->subYear()->year;

    // Simulate a document from last year
    Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => "DN-{$lastYear}-0050",
        'created_at' => now()->subYear(),
    ]);

    $generator = new DocumentNumberGenerator;
    $year = now()->year;

    expect($generator->nextFor('DN'))->toBe("DN-{$year}-0001");
});
