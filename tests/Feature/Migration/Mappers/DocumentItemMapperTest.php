<?php

use App\Models\Document;
use App\Models\DocumentItem;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\DocumentItemMapper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents', 'DocumentDetails']);

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

    DB::connection('legacy')->table('DocumentDetails')->insert([
        'uid' => 701,
        'rtype' => 'd',
        'bline' => 42,
        'details' => 'Widget',
        'qty' => 3,
        'price' => 10,
        'unitdesc' => 'Box',
        'value' => 30,
    ]);

    $row = collect($this->mapper->rows(500))->firstWhere('uid', 701);

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Added);

    $item = DocumentItem::where('legacy_uid', 701)->first();

    expect($item)->not->toBeNull()
        ->and($item->document_id)->toBe($document->id)
        ->and($item->details)->toBe('Widget');
});

test('rows/count only include detail rows whose parent document exists in legacy Documents and was migrated locally', function () {
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

    expect($this->mapper->count())->toBe(1);

    $rows = collect($this->mapper->rows(500))->all();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['uid'])->toBe(801);
    expect($rows[0]['parent_legacy_uid'])->toBe(611);
});

test('apply skips a detail row with no matching legacy document header', function () {
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
