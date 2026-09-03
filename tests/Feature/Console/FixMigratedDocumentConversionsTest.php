<?php

use App\DocumentStatus;
use App\Models\Customer;
use App\Models\Document;

test('links a delivery note to its invoice when numbers match for the same customer', function () {
    $customer = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-1234',
        'legacy_uid' => 5001,
        'status' => DocumentStatus::Active,
    ]);

    $inv = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-1234',
        'legacy_uid' => 5002,
        'converted_from_id' => null,
    ]);

    $this->artisan('documents:fix-migrated-conversions')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($inv->fresh()->converted_from_id)->toBe($dn->id)
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('matches despite an ordinal suffix on one side', function () {
    $customer = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-1234-1',
        'legacy_uid' => 5011,
        'status' => DocumentStatus::Active,
    ]);

    $inv = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-1234',
        'legacy_uid' => 5012,
        'converted_from_id' => null,
    ]);

    $this->artisan('documents:fix-migrated-conversions')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($inv->fresh()->converted_from_id)->toBe($dn->id)
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('does not match documents of different customers sharing a ref', function () {
    $customerA = Customer::factory()->create();
    $customerB = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customerA->id,
        'doc_number' => 'DN-1234',
        'legacy_uid' => 5021,
        'status' => DocumentStatus::Active,
    ]);

    $inv = Document::factory()->invoice()->create([
        'customer_id' => $customerB->id,
        'doc_number' => 'INV-1234',
        'legacy_uid' => 5022,
        'converted_from_id' => null,
    ]);

    $this->artisan('documents:fix-migrated-conversions')
        ->expectsOutputToContain('No migrated conversions to link.')
        ->assertSuccessful();

    expect($inv->fresh()->converted_from_id)->toBeNull()
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Active);
});

test('does not match documents without a legacy_uid', function () {
    $customer = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-1234',
        'legacy_uid' => null,
        'status' => DocumentStatus::Active,
    ]);

    $inv = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-1234',
        'legacy_uid' => null,
        'converted_from_id' => null,
    ]);

    $this->artisan('documents:fix-migrated-conversions')
        ->expectsOutputToContain('No migrated conversions to link.')
        ->assertSuccessful();

    expect($inv->fresh()->converted_from_id)->toBeNull()
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Active);
});

test('skips an ambiguous group with two delivery notes for one invoice', function () {
    $customer = Customer::factory()->create();

    $firstDn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-1234',
        'legacy_uid' => 5031,
        'status' => DocumentStatus::Active,
    ]);

    $secondDn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-1234-1',
        'legacy_uid' => 5032,
        'status' => DocumentStatus::Active,
    ]);

    $inv = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-1234',
        'legacy_uid' => 5033,
        'converted_from_id' => null,
    ]);

    $this->artisan('documents:fix-migrated-conversions')
        ->expectsOutputToContain('ambiguous')
        ->assertSuccessful();

    expect($inv->fresh()->converted_from_id)->toBeNull()
        ->and($firstDn->fresh()->status)->toBe(DocumentStatus::Active)
        ->and($secondDn->fresh()->status)->toBe(DocumentStatus::Active);
});

test('is idempotent', function () {
    $customer = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-1234',
        'legacy_uid' => 5041,
        'status' => DocumentStatus::Active,
    ]);

    $inv = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-1234',
        'legacy_uid' => 5042,
        'converted_from_id' => null,
    ]);

    $this->artisan('documents:fix-migrated-conversions')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    $this->artisan('documents:fix-migrated-conversions')
        ->expectsOutputToContain('No migrated conversions to link.')
        ->assertSuccessful();

    expect($inv->fresh()->converted_from_id)->toBe($dn->id)
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Converted);
});

test('--dry-run makes no changes', function () {
    $customer = Customer::factory()->create();

    $dn = Document::factory()->deliveryNote()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'DN-1234',
        'legacy_uid' => 5051,
        'status' => DocumentStatus::Active,
    ]);

    $inv = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_number' => 'INV-1234',
        'legacy_uid' => 5052,
        'converted_from_id' => null,
    ]);

    $this->artisan('documents:fix-migrated-conversions', ['--dry-run' => true])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect($inv->fresh()->converted_from_id)->toBeNull()
        ->and($dn->fresh()->status)->toBe(DocumentStatus::Active);
});
