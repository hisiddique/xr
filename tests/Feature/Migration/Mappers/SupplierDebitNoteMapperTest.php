<?php

use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\SupplierDebitNoteMapper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents']);

    $this->supplier = Supplier::factory()->create(['legacy_uid' => 501]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 601,
        'rtype' => 'v',
        'acctuid' => 501,
        'orderno' => null,
        'date' => '2024-03-01',
        'goods' => 40,
        'value' => 48,
        'notes' => 'Returned goods',
        'ref' => 'PCN-1',
        'bline' => 10,
        'deleteddate' => null,
    ]);

    $this->mapper = new SupplierDebitNoteMapper;
    $this->mapper->setCreatedBy(1);
    $this->legacyRow = (array) DB::connection('legacy')->table('Documents')->where('uid', 601)->first();
});

test('apply creates a supplier debit note for a matched supplier', function () {
    $outcome = $this->mapper->apply($this->legacyRow, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Added);

    $note = SupplierDebitNote::where('legacy_uid', 601)->first();

    expect($note)->not->toBeNull()
        ->and($note->supplier_id)->toBe($this->supplier->id)
        ->and($note->reference)->toBe('PCN-1')
        ->and($note->deleted_at)->toBeNull();
});

test('apply skips a debit note whose supplier has not been migrated', function () {
    $row = $this->legacyRow;
    $row['acctuid'] = 999999;

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(SupplierDebitNote::count())->toBe(0);
});

test('apply carries a legacy Deleteddate through to deleted_at, so the note migrates soft-deleted', function () {
    $row = $this->legacyRow;
    $row['deleteddate'] = 'Nov 22 2016 12:00:00:AM';

    $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    $note = SupplierDebitNote::withTrashed()->where('legacy_uid', 601)->first();

    expect($note)->not->toBeNull()
        ->and($note->deleted_at)->not->toBeNull()
        ->and($note->trashed())->toBeTrue();
});
