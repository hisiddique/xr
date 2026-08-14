<?php

use App\Models\Payment;
use App\Models\Setting;
use App\Services\PaymentNumberGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::set('pay_prefix', 'PAY');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

test('first payment gets number PAY-0001', function () {
    $generator = new PaymentNumberGenerator;

    expect($generator->next())->toBe('PAY-0001');
});

test('second payment gets incrementing number', function () {
    Payment::factory()->create();

    $generator = new PaymentNumberGenerator;

    expect($generator->next())->toBe('PAY-0002');
});

test('soft-deleted payments still occupy their number so it is not reused', function () {
    $payment = Payment::factory()->create();
    $payment->delete();

    $generator = new PaymentNumberGenerator;

    expect($generator->next())->toBe('PAY-0002');
});
