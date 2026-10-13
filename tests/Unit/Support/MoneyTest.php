<?php

use App\Support\Money;

test('roundUp raises any fractional penny to the next penny', function (float $input, float $expected) {
    expect(Money::roundUp($input))->toBe($expected);
})->with([
    'fraction below half' => [0.544, 0.55],
    'exact amount is untouched' => [5.33, 5.33],
    'float noise is not rounded up' => [26.65 * 0.2, 5.33],
    'zero' => [0.0, 0.0],
]);
