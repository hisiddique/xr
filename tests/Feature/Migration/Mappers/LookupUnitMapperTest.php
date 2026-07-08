<?php

use App\Models\LookupUnit;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\LookupUnitMapper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Units']);

    DB::connection('legacy')->table('Units')->insert([
        ['uid' => 1, 'name' => 'Box', 'status' => 'A'],
        ['uid' => 2, 'name' => 'Pallet', 'status' => 'A'],
        ['uid' => 3, 'name' => 'Retired Unit', 'status' => 'I'],
    ]);

    $this->mapper = new LookupUnitMapper;
});

test('count only counts active units', function () {
    expect($this->mapper->count())->toBe(2);
});

test('rows yields only active units', function () {
    $names = collect($this->mapper->rows(500))->pluck('name')->all();

    expect($names)->toBe(['Box', 'Pallet']);
});

test('apply creates a new lookup unit', function () {
    $row = ['name' => 'Box'];

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Added);
    expect(LookupUnit::where('name', 'Box')->count())->toBe(1);
});

test('apply skips a case-insensitive duplicate name', function () {
    LookupUnit::create(['name' => 'Box']);

    $outcome = $this->mapper->apply(['name' => 'BOX'], DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(LookupUnit::count())->toBe(1);
});
