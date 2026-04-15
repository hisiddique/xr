<?php

use App\Models\Customer;
use App\Models\Setting;
use App\Services\DocumentTotalsCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('vat_rate', '20', 'float');
    Setting::flushCache();
});

test('calculates subtotal, vat and total correctly', function () {
    $customer = Customer::factory()->make(['trade_discount' => 0]);
    $items = collect([
        ['quantity' => '2', 'price' => '50.00'],
        ['quantity' => '1', 'price' => '100.00'],
    ]);

    $calculator = new DocumentTotalsCalculator();
    $result = $calculator->calculate($items, $customer);

    expect($result['subtotal'])->toBe(200.0)
        ->and($result['discount_amount'])->toBe(0.0)
        ->and($result['vat'])->toBe(40.0)
        ->and($result['total'])->toBe(240.0);
});

test('applies trade discount before VAT', function () {
    $customer = Customer::factory()->make(['trade_discount' => 10]);
    $items = collect([
        ['quantity' => '1', 'price' => '100.00'],
    ]);

    $calculator = new DocumentTotalsCalculator();
    $result = $calculator->calculate($items, $customer);

    expect($result['subtotal'])->toBe(100.0)
        ->and($result['discount_amount'])->toBe(10.0)
        ->and($result['vat'])->toBe(18.0) // 20% of 90
        ->and($result['total'])->toBe(108.0);
});

test('empty items collection returns zero totals', function () {
    $customer = Customer::factory()->make(['trade_discount' => 0]);
    $items = collect([]);

    $calculator = new DocumentTotalsCalculator();
    $result = $calculator->calculate($items, $customer);

    expect($result['subtotal'])->toBe(0.0)
        ->and($result['vat'])->toBe(0.0)
        ->and($result['total'])->toBe(0.0);
});

test('100 percent discount results in zero total', function () {
    $customer = Customer::factory()->make(['trade_discount' => 100]);
    $items = collect([
        ['quantity' => '1', 'price' => '500.00'],
    ]);

    $calculator = new DocumentTotalsCalculator();
    $result = $calculator->calculate($items, $customer);

    expect($result['discount_amount'])->toBe(500.0)
        ->and($result['vat'])->toBe(0.0)
        ->and($result['total'])->toBe(0.0);
});

test('uses VAT rate from settings', function () {
    Setting::set('vat_rate', '5', 'float');
    Setting::flushCache();

    $customer = Customer::factory()->make(['trade_discount' => 0]);
    $items = collect([
        ['quantity' => '1', 'price' => '100.00'],
    ]);

    $calculator = new DocumentTotalsCalculator();
    $result = $calculator->calculate($items, $customer);

    expect($result['vat'])->toBe(5.0)
        ->and($result['total'])->toBe(105.0);
});
