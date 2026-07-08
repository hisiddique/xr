<?php

use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\DocumentMapper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents']);

    $this->user = User::factory()->create();
    $this->mapper = (new DocumentMapper)->setCreatedBy($this->user->id);
});

test('apply skips a document whose customer has not been migrated yet', function () {
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 501,
        'rtype' => 'd',
        'acctuid' => 999,
        'orderno' => 'PO-1',
        'date' => '2024-01-15',
        'goods' => 100,
        'value' => 120,
        'notes' => null,
        'ref' => '5001',
        'bline' => 0,
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 501)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(Document::count())->toBe(0);
});

test('apply resolves the customer and creates a document once the customer exists', function () {
    $customer = Customer::factory()->create(['legacy_uid' => 999]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 502,
        'rtype' => 'd',
        'acctuid' => 999,
        'orderno' => 'PO-2',
        'date' => '2024-02-01',
        'goods' => 100,
        'value' => 120,
        'notes' => 'test note',
        'ref' => '5002',
        'bline' => 0,
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 502)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Added);

    $document = Document::where('legacy_uid', 502)->first();

    expect($document)->not->toBeNull()
        ->and($document->customer_id)->toBe($customer->id)
        ->and($document->type)->toBe(DocumentType::DeliveryNote);
});

test('apply skips a document with an unrecognized Rtype without throwing', function () {
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 503,
        'rtype' => '?',
        'acctuid' => 999,
        'orderno' => null,
        'date' => '2024-02-01',
        'goods' => 0,
        'value' => 0,
        'notes' => null,
        'ref' => null,
        'bline' => 0,
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 503)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(Document::count())->toBe(0);
});
