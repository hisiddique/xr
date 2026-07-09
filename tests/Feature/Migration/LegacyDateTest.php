<?php

use App\Services\Migration\Support\LegacyDate;

test('parses the malformed FreeTDS/pdo_dblib date format with a colon before AM/PM', function () {
    expect(LegacyDate::parse('Nov 11 2021 12:00:00:AM'))->toBe('2021-11-11 00:00:00');
    expect(LegacyDate::parse('May 30 2019 12:00:00:AM'))->toBe('2019-05-30 00:00:00');
    expect(LegacyDate::parse('Dec 31 2021 11:59:59:PM'))->toBe('2021-12-31 23:59:59');
});

test('parses already-clean date strings', function () {
    expect(LegacyDate::parse('2024-01-01'))->toBe('2024-01-01 00:00:00');
});

test('returns null for blank or genuinely unparseable values', function () {
    expect(LegacyDate::parse(null))->toBeNull();
    expect(LegacyDate::parse(''))->toBeNull();
    expect(LegacyDate::parse('not a date at all'))->toBeNull();
});
