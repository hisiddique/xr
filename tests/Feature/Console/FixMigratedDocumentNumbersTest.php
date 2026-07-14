<?php

use App\DocumentType;
use App\Models\Document;

test('it prefixes bare migrated doc_numbers and drops incorrect suffixes when there is no real collision', function () {
    $dn = Document::factory()->create([
        'type' => DocumentType::DeliveryNote,
        'doc_number' => '612883-1',
        'legacy_uid' => 901,
    ]);

    $inv = Document::factory()->create([
        'type' => DocumentType::Invoice,
        'doc_number' => '612883-2',
        'legacy_uid' => 902,
    ]);

    $this->artisan('documents:fix-migrated-numbers')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect($dn->fresh()->doc_number)->toBe('DN-612883')
        ->and($inv->fresh()->doc_number)->toBe('INV-612883');
});

test('it keeps a numeric suffix when two documents of the same type genuinely share a reference', function () {
    $first = Document::factory()->create([
        'type' => DocumentType::Invoice,
        'doc_number' => '700100',
        'legacy_uid' => 911,
    ]);

    $second = Document::factory()->create([
        'type' => DocumentType::Invoice,
        'doc_number' => '700100-2',
        'legacy_uid' => 912,
    ]);

    $this->artisan('documents:fix-migrated-numbers')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect($first->fresh()->doc_number)->toBe('INV-700100-1')
        ->and($second->fresh()->doc_number)->toBe('INV-700100-2');
});

test('it leaves non-migrated documents untouched', function () {
    $document = Document::factory()->create([
        'type' => DocumentType::DeliveryNote,
        'doc_number' => 'DN-0001',
        'legacy_uid' => null,
    ]);

    $this->artisan('documents:fix-migrated-numbers')
        ->assertExitCode(0);

    expect($document->fresh()->doc_number)->toBe('DN-0001');
});

test('it leaves already-correctly-prefixed doc_numbers unchanged, without double-prefixing or corrupting them', function () {
    $dn = Document::factory()->create([
        'type' => DocumentType::DeliveryNote,
        'doc_number' => 'DN-612883',
        'legacy_uid' => 901,
    ]);

    $inv = Document::factory()->create([
        'type' => DocumentType::Invoice,
        'doc_number' => 'INV-700100-1',
        'legacy_uid' => 911,
    ]);

    $inv2 = Document::factory()->create([
        'type' => DocumentType::Invoice,
        'doc_number' => 'INV-700100-2',
        'legacy_uid' => 912,
    ]);

    $this->artisan('documents:fix-migrated-numbers')
        ->assertExitCode(0);

    expect($dn->fresh()->doc_number)->toBe('DN-612883')
        ->and($inv->fresh()->doc_number)->toBe('INV-700100-1')
        ->and($inv2->fresh()->doc_number)->toBe('INV-700100-2');
});

test('running the command twice is a no-op the second time', function () {
    $dn = Document::factory()->create([
        'type' => DocumentType::DeliveryNote,
        'doc_number' => '612883-1',
        'legacy_uid' => 901,
    ]);

    $inv = Document::factory()->create([
        'type' => DocumentType::Invoice,
        'doc_number' => '612883-2',
        'legacy_uid' => 902,
    ]);

    $this->artisan('documents:fix-migrated-numbers')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect($dn->fresh()->doc_number)->toBe('DN-612883')
        ->and($inv->fresh()->doc_number)->toBe('INV-612883');

    $this->artisan('documents:fix-migrated-numbers')
        ->assertExitCode(0);

    expect($dn->fresh()->doc_number)->toBe('DN-612883')
        ->and($inv->fresh()->doc_number)->toBe('INV-612883');
});

test('dry-run reports changes without writing them', function () {
    $document = Document::factory()->create([
        'type' => DocumentType::DeliveryNote,
        'doc_number' => '612883-1',
        'legacy_uid' => 901,
    ]);

    $this->artisan('documents:fix-migrated-numbers --dry-run')
        ->assertExitCode(0);

    expect($document->fresh()->doc_number)->toBe('612883-1');
});
