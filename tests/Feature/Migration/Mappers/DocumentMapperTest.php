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
    createLegacyTables(['Documents', 'CustSupps']);

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

test('rows/count only include documents whose customer exists in legacy CustSupps', function () {
    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 601, 'rtype' => 'A', 'name' => 'Real Customer',
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 601, 'rtype' => 'd', 'acctuid' => 601, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '6001', 'bline' => 0],
        ['uid' => 602, 'rtype' => 'd', 'acctuid' => 999999, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '6002', 'bline' => 0],
    ]);

    expect($this->mapper->count())->toBe(1);

    $rows = collect($this->mapper->rows(500))->all();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['uid'])->toBe(601);
});

test('excludedCount reports documents whose customer does not exist in legacy at all, without fetching them', function () {
    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 611, 'rtype' => 'A', 'name' => 'Real Customer',
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 701, 'rtype' => 'd', 'acctuid' => 611, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '7001', 'bline' => 0],
        ['uid' => 702, 'rtype' => 'd', 'acctuid' => 888888, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '7002', 'bline' => 0],
        ['uid' => 703, 'rtype' => 'i', 'acctuid' => 777777, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '7003', 'bline' => 0],
    ]);

    expect($this->mapper->excludedCount())->toBe(2);
    expect($this->mapper->count())->toBe(1);
});

test('transform disambiguates doc_number with -1/-2 suffixes when a ref is shared by multiple documents, e.g. a delivery note and its converted invoice', function () {
    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 801, 'rtype' => 'A', 'name' => 'Real Customer',
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 901, 'rtype' => 'd', 'acctuid' => 801, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '612883', 'bline' => 0],
        ['uid' => 902, 'rtype' => 'i', 'acctuid' => 801, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '612883', 'bline' => 0],
        ['uid' => 903, 'rtype' => 'd', 'acctuid' => 801, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 10, 'value' => 12, 'notes' => null, 'ref' => '999999', 'bline' => 0],
    ]);

    Customer::factory()->create(['legacy_uid' => 801]);

    $rows = collect(DB::connection('legacy')->table('Documents')->orderBy('uid')->get())
        ->map(fn ($row) => (array) $row);

    $docNumbers = $rows->mapWithKeys(fn ($row) => [$row['uid'] => $this->mapper->transform($row)['doc_number']]);

    expect($docNumbers[901])->toBe('612883-1')
        ->and($docNumbers[902])->toBe('612883-2')
        ->and($docNumbers[903])->toBe('999999');
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
