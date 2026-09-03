<?php

use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\DocumentMapper;
use App\UserStatus;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents', 'CustSupps', 'AppCodes']);

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

test('transform prefixes doc_number by type and does not suffix a delivery note and its converted invoice sharing a ref', function () {
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

    expect($docNumbers[901])->toBe('DN-612883')
        ->and($docNumbers[902])->toBe('INV-612883')
        ->and($docNumbers[903])->toBe('DN-999999');
});

test('transform disambiguates doc_number with -1/-2 suffixes when two documents of the same type genuinely share a ref', function () {
    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 811, 'rtype' => 'A', 'name' => 'Real Customer',
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 911, 'rtype' => 'i', 'acctuid' => 811, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '700100', 'bline' => 0],
        ['uid' => 912, 'rtype' => 'i', 'acctuid' => 811, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 10, 'value' => 12, 'notes' => null, 'ref' => '700100', 'bline' => 0],
    ]);

    Customer::factory()->create(['legacy_uid' => 811]);

    $rows = collect(DB::connection('legacy')->table('Documents')->orderBy('uid')->get())
        ->map(fn ($row) => (array) $row);

    $docNumbers = $rows->mapWithKeys(fn ($row) => [$row['uid'] => $this->mapper->transform($row)['doc_number']]);

    expect($docNumbers[911])->toBe('INV-700100-1')
        ->and($docNumbers[912])->toBe('INV-700100-2');
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

test('apply carries a legacy Deleteddate through to deleted_at, so the document migrates soft-deleted', function () {
    $customer = Customer::factory()->create(['legacy_uid' => 998]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 504,
        'rtype' => 'd',
        'acctuid' => 998,
        'orderno' => null,
        'date' => '2024-02-01',
        'goods' => 10,
        'value' => 12,
        'notes' => null,
        'ref' => '5004',
        'bline' => 0,
        'deleteddate' => 'Nov 22 2016 12:00:00:AM',
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 504)->first();

    $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    $document = Document::withTrashed()->where('legacy_uid', 504)->first();

    expect($document)->not->toBeNull()
        ->and($document->deleted_at)->not->toBeNull()
        ->and($document->trashed())->toBeTrue();
});

test('apply resolves Salesman via AppCodes into a placeholder Migrated user, reused across documents sharing the same code', function () {
    $customer = Customer::factory()->create(['legacy_uid' => 997]);

    DB::connection('legacy')->table('AppCodes')->insert([
        'codetype' => 'Salesman', 'valueint' => 177, 'description' => 'Colin B',
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 505, 'rtype' => 'd', 'acctuid' => 997, 'orderno' => null, 'date' => '2024-02-01', 'goods' => 10, 'value' => 12, 'notes' => null, 'ref' => '5005', 'bline' => 0, 'salesman' => 177],
        ['uid' => 506, 'rtype' => 'i', 'acctuid' => 997, 'orderno' => null, 'date' => '2024-02-01', 'goods' => 10, 'value' => 12, 'notes' => null, 'ref' => '5006', 'bline' => 1, 'salesman' => 177],
    ]);

    foreach ([505, 506] as $uid) {
        $row = (array) DB::connection('legacy')->table('Documents')->where('uid', $uid)->first();
        $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);
    }

    $documents = Document::whereIn('legacy_uid', [505, 506])->get();
    $assignedIds = $documents->pluck('assigned_to')->unique();

    expect($assignedIds)->toHaveCount(1);

    $placeholder = User::find($assignedIds->first());

    expect($placeholder)->not->toBeNull()
        ->and($placeholder->name)->toBe('Colin B')
        ->and($placeholder->status)->toBe(UserStatus::Migrated)
        ->and($placeholder->canLogIn())->toBeFalse();
});

test('apply leaves assigned_to null when Salesman is 0 (no salesperson set)', function () {
    $customer = Customer::factory()->create(['legacy_uid' => 996]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 507, 'rtype' => 'd', 'acctuid' => 996, 'orderno' => null, 'date' => '2024-02-01',
        'goods' => 10, 'value' => 12, 'notes' => null, 'ref' => '5007', 'bline' => 0, 'salesman' => 0,
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 507)->first();
    $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect(Document::where('legacy_uid', 507)->first()->assigned_to)->toBeNull();
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

test('transform marks a converted delivery note', function () {
    Customer::factory()->create(['legacy_uid' => 820]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 920, 'rtype' => 'd', 'acctuid' => 820, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '920920', 'bline' => 0,
        'invuid' => 55501,
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 920)->first();

    expect($this->mapper->transform($row)['status'])->toBe('converted');
});

test('transform leaves an unconverted delivery note active', function () {
    Customer::factory()->create(['legacy_uid' => 821]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 921, 'rtype' => 'd', 'acctuid' => 821, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '921921', 'bline' => 0,
        'invuid' => null,
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 921)->first();

    expect($this->mapper->transform($row)['status'])->toBe('active');
});

test('transform marks an emailed invoice', function () {
    Customer::factory()->create(['legacy_uid' => 822]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 922, 'rtype' => 'i', 'acctuid' => 822, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '922922', 'bline' => 0,
        'emailsent' => '2024-03-02 09:15:00',
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 922)->first();

    expect($this->mapper->transform($row)['status'])->toBe('emailed');
});

test('transform never marks an invoice converted even with origdeln set', function () {
    Customer::factory()->create(['legacy_uid' => 823]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 923, 'rtype' => 'i', 'acctuid' => 823, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '923923', 'bline' => 0,
        'origdeln' => 900, 'emailsent' => '2024-03-02 09:15:00',
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 923)->first();

    expect($this->mapper->transform($row)['status'])->toBe('emailed');
});

test('transform prefers converted over emailed for a delivery note', function () {
    Customer::factory()->create(['legacy_uid' => 824]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 924, 'rtype' => 'd', 'acctuid' => 824, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '924924', 'bline' => 0,
        'invuid' => 55502, 'emailsent' => '2024-03-02 09:15:00',
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 924)->first();

    expect($this->mapper->transform($row)['status'])->toBe('converted');
});

test('transform derives print_count from printtime', function () {
    Customer::factory()->create(['legacy_uid' => 825]);

    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 925, 'rtype' => 'd', 'acctuid' => 825, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '925925', 'bline' => 0, 'printtime' => '2024-03-01 10:00:00', 'status' => null],
        ['uid' => 926, 'rtype' => 'd', 'acctuid' => 825, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '926926', 'bline' => 0, 'printtime' => null, 'status' => 0],
    ]);

    $printed = (array) DB::connection('legacy')->table('Documents')->where('uid', 925)->first();
    $unprinted = (array) DB::connection('legacy')->table('Documents')->where('uid', 926)->first();

    expect($this->mapper->transform($printed)['print_count'])->toBe(1)
        ->and($this->mapper->transform($unprinted)['print_count'])->toBe(0);
});

test('transform derives print_count from the legacy status byte', function () {
    Customer::factory()->create(['legacy_uid' => 826]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 927, 'rtype' => 'd', 'acctuid' => 826, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '927927', 'bline' => 0,
        'printtime' => null, 'status' => 1,
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 927)->first();

    expect($this->mapper->transform($row)['print_count'])->toBe(1);
});

test('transform is stable when run twice on the same row', function () {
    Customer::factory()->create(['legacy_uid' => 827]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 928, 'rtype' => 'd', 'acctuid' => 827, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 35, 'value' => 42, 'notes' => null, 'ref' => '928928', 'bline' => 0,
        'invuid' => 55503, 'printtime' => '2024-03-01 10:00:00',
    ]);

    $row = (array) DB::connection('legacy')->table('Documents')->where('uid', 928)->first();

    expect($this->mapper->transform($row))->toEqual($this->mapper->transform($row));
});
