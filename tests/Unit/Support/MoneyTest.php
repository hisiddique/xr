<?php

use App\Support\Money;

test('roundDown drops any fractional penny', function (float $input, float $expected) {
    expect(Money::roundDown($input))->toBe($expected);
})->with([
    'above half a penny' => [0.546, 0.54],
    'exact amount is untouched' => [5.33, 5.33],
    'float noise is not rounded down' => [0.07 * 100 / 100, 0.07],
    'zero' => [0.0, 0.0],
]);

test('roundUp raises any fractional penny to the next penny', function (float $input, float $expected) {
    expect(Money::roundUp($input))->toBe($expected);
})->with([
    'fraction below half' => [0.544, 0.55],
    'exact amount is untouched' => [5.33, 5.33],
    'float noise is not rounded up' => [26.65 * 0.2, 5.33],
    'zero' => [0.0, 0.0],
]);
