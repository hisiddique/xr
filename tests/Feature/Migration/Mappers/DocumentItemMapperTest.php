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

    $row = (array) DB::connection('legacy')->table('DocumentDetails')->where('uid', 701)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Added);

    $item = DocumentItem::where('legacy_uid', 701)->first();

    expect($item)->not->toBeNull()
        ->and($item->document_id)->toBe($document->id)
        ->and($item->details)->toBe('Widget');
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
