<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\WriteOff;
use App\Services\CustomerStatementService;
use App\Support\StatementFilters;
use App\Support\StatementPeriod;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('StatementPeriod resolves presets relative to asOf', function () {
    $asOf = Carbon::parse('2026-03-15');

    $lastMonth = StatementPeriod::fromPreset('last_month', asOf: $asOf);
    expect($lastMonth->from)->toBe('2026-02-01')
        ->and($lastMonth->to)->toBe('2026-02-28')
        ->and($lastMonth->label)->toBe('01 Feb 2026 – 28 Feb 2026');

    $thisMonth = StatementPeriod::fromPreset('this_month', asOf: $asOf);
    expect($thisMonth->from)->toBe('2026-03-01')->and($thisMonth->to)->toBe('2026-03-31');

    $custom = StatementPeriod::fromPreset('custom', '2026-01-05', '2026-01-20', $asOf);
    expect($custom->from)->toBe('2026-01-05')->and($custom->to)->toBe('2026-01-20');
});

test('StatementFilters::fromRules maps the customer rule shape to the flat filter array', function () {
    $filters = StatementFilters::fromRules([
        'preset' => 'last_month',
        'outstanding_only' => false,
        'min_balance' => '250',
        'include_invoices' => true,
        'include_write_offs' => true,
        'payment_methods' => ['3', 5],
    ], Carbon::parse('2026-03-15'));

    $array = $filters->toArray();

    expect($array['dateFrom'])->toBe('2026-02-01')
        ->and($array['outstandingOnly'])->toBeFalse()
        ->and($array['minBalance'])->toBe('250')
        ->and($array['includeWriteOffs'])->toBeTrue()
        ->and($array['paymentMethods'])->toBe([3, 5]);
});

test('buildStatementRows includes write-off rows only when requested and never shifts the aging total', function () {
    $customer = Customer::factory()->create();
    $service = app(CustomerStatementService::class);

    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_date' => now()->subMonthNoOverflow(),
        'total_value' => 400,
    ]);

    WriteOff::factory()->create([
        'document_id' => $invoice->id,
        'amount' => 120,
        'written_off_at' => now()->subMonthNoOverflow(),
    ]);

    $withoutWriteOffs = $service->buildStatementRows($customer, ['includeInvoices' => true]);
    $withWriteOffs = $service->buildStatementRows($customer, [
        'includeInvoices' => true,
        'includeWriteOffs' => true,
    ]);

    expect($withoutWriteOffs)->toHaveCount(1)
        ->and($withWriteOffs)->toHaveCount(2)
        ->and($service->agingBuckets($withWriteOffs)['total'])
        ->toBe($service->agingBuckets($withoutWriteOffs)['total']);

    $writeOffRow = collect($withWriteOffs)->firstWhere('row_type', 'write_off');
    expect($writeOffRow['credited'])->toBe(120.0)
        ->and($writeOffRow['order_no'])->toBe($invoice->doc_number);
});

test('buildPaymentRows filters by payment method when ids are given', function () {
    $customer = Customer::factory()->create();
    $bank = LookupPaymentMethod::factory()->create(['name' => 'Bank Transfer']);
    $cash = LookupPaymentMethod::factory()->create(['name' => 'Cash']);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $bank->id,
        'amount' => 100,
    ]);
    Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_method_id' => $cash->id,
        'amount' => 200,
    ]);

    $service = app(CustomerStatementService::class);

    expect($service->buildPaymentRows($customer, []))->toHaveCount(2)
        ->and($service->buildPaymentRows($customer, ['paymentMethods' => [$bank->id]]))->toHaveCount(1);
});

test('agingBuckets honours the asOf argument', function () {
    // An invoice dated "this month" relative to a mid-month asOf falls entirely in the
    // current month and must be excluded from every bucket.
    $rows = [
        ['doc_date' => Carbon::parse('2026-03-10')->format('d M Y'), 'outstanding' => 90.0],
    ];

    $asOfMidMarch = app(CustomerStatementService::class)->agingBuckets($rows, Carbon::parse('2026-03-20'));
    expect($asOfMidMarch['total'])->toBe(0.0);

    // Seen from April, that same invoice now lands in the March bucket.
    $asOfApril = app(CustomerStatementService::class)->agingBuckets($rows, Carbon::parse('2026-04-05'));
    expect($asOfApril['labels']['March'])->toBe(90.0);
});
