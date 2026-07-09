<?php

use App\Models\Document;
use App\Models\DocumentItem;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\DocumentItemMapper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents', 'DocumentDetails', 'Units', 'CustSupps']);

    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 1, 'rtype' => 'A', 'name' => 'Real Customer',
    ]);

    $this->mapper = new DocumentItemMapper;
});

test('apply resolves the parent document via matching Bline/Rtype and creates a line item', function () {
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 601,
        'rtype' => 'd',
        'acctuid' => 1,
        'orderno' => null,
        'date' => '2024-01-01',
        'goods' => 0,
        'value' => 0,
        'notes' => null,
        'ref' => '6001',
        'bline' => 42,
    ]);

    $document = Document::factory()->deliveryNote()->create(['legacy_uid' => 601]);

    DB::connection('legacy')->table('Units')->insert([
        'uid' => 300, 'name' => 'Box', 'status' => 'S', 'recstate' => 'A',
    ]);

    DB::connection('legacy')->table('DocumentDetails')->insert([
        'uid' => 701,
        'rtype' => 'd',
        'bline' => 42,
        'details' => 'Widget',
        'qty' => 3,
        'price' => 10,
        'unitdesc' => '          ',
        'unituid' => 300,
        'value' => 30,
    ]);

    $row = collect($this->mapper->rows(500))->firstWhere('uid', 701);

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Added);

    $item = DocumentItem::where('legacy_uid', 701)->first();

    expect($item)->not->toBeNull()
        ->and($item->document_id)->toBe($document->id)
        ->and($item->details)->toBe('Widget')
        ->and($item->per)->toBe('Box');
});

test('transform resolves per via Units.Unituid, ignoring the always-blank Unitdesc column, and leaves per null when Unituid has no match', function () {
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 621, 'rtype' => 'd', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '6211', 'bline' => 60,
    ]);

    Document::factory()->deliveryNote()->create(['legacy_uid' => 621]);

    DB::connection('legacy')->table('Units')->insert([
        'uid' => 0, 'name' => 'Each', 'status' => 'S', 'recstate' => 'A',
    ]);

    DB::connection('legacy')->table('DocumentDetails')->insert([
        ['uid' => 901, 'rtype' => 'd', 'bline' => 60, 'details' => 'Matches Units.uid=0', 'qty' => 1, 'price' => 1, 'unitdesc' => '          ', 'unituid' => 0, 'value' => 1],
        ['uid' => 902, 'rtype' => 'd', 'bline' => 60, 'details' => 'No matching Units row', 'qty' => 1, 'price' => 1, 'unitdesc' => '          ', 'unituid' => 999999, 'value' => 1],
    ]);

    $rows = collect($this->mapper->rows(500))->keyBy('uid');

    expect($this->mapper->transform($rows[901])['per'])->toBe('Each');
    expect($this->mapper->transform($rows[902])['per'])->toBeNull();
});

test('rows/count include detail rows whenever their parent document has a real legacy customer, regardless of local migration state', function () {
    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 611, 'rtype' => 'd', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '6111', 'bline' => 55],
        ['uid' => 612, 'rtype' => 'd', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '6112', 'bline' => 56],
    ]);

    // Only 611 has a corresponding local document — 612's legacy header exists but was never migrated.
    Document::factory()->deliveryNote()->create(['legacy_uid' => 611]);

    DB::connection('legacy')->table('DocumentDetails')->insert([
        ['uid' => 801, 'rtype' => 'd', 'bline' => 55, 'details' => 'Has migrated parent', 'qty' => 1, 'price' => 1, 'unitdesc' => 'Each', 'value' => 1],
        ['uid' => 802, 'rtype' => 'd', 'bline' => 56, 'details' => 'Parent exists in legacy but not migrated locally', 'qty' => 1, 'price' => 1, 'unitdesc' => 'Each', 'value' => 1],
        ['uid' => 803, 'rtype' => 'd', 'bline' => 999, 'details' => 'No legacy parent at all', 'qty' => 1, 'price' => 1, 'unitdesc' => 'Each', 'value' => 1],
    ]);

    expect($this->mapper->count())->toBe(2);

    $rows = collect($this->mapper->rows(500))->keyBy('uid');
    expect($rows->has(801))->toBeTrue();
    expect($rows->has(802))->toBeTrue();
    expect($rows->has(803))->toBeFalse();
});

test('apply skips a detail row whose parent document exists in legacy but was never migrated locally', function () {
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 612, 'rtype' => 'd', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '6112', 'bline' => 56,
    ]);

    DB::connection('legacy')->table('DocumentDetails')->insert([
        'uid' => 802, 'rtype' => 'd', 'bline' => 56, 'details' => 'Orphan-ish', 'qty' => 1, 'price' => 1, 'unitdesc' => 'Each', 'value' => 1,
    ]);

    $row = collect($this->mapper->rows(500))->firstWhere('uid', 802);

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(DocumentItem::count())->toBe(0);
});

test('apply skips a detail row with no matching legacy document header at all', function () {
    DB::connection('legacy')->table('DocumentDetails')->insert([
        'uid' => 702,
        'rtype' => 'd',
        'bline' => 999,
        'details' => 'Orphan',
        'qty' => 1,
        'price' => 5,
        'unitdesc' => 'Each',
        'value' => 5,
    ]);

    $row = (array) DB::connection('legacy')->table('DocumentDetails')->where('uid', 702)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(DocumentItem::count())->toBe(0);
});
