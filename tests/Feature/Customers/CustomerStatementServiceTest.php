<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Services\CustomerStatementService;

test('buildInvoiceRows filters invoices by date range', function () {
    $customer = Customer::factory()->create();

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_date' => '2026-01-15',
        'total_value' => 100,
    ]);

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_date' => '2026-03-15',
        'total_value' => 200,
    ]);

    $rows = app(CustomerStatementService::class)->buildInvoiceRows($customer, [
        'dateFrom' => '2026-03-01',
        'dateTo' => '2026-03-31',
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total_value'])->toBe(200.0);
});

test('buildInvoiceRows outstandingOnly excludes settled invoices', function () {
    $customer = Customer::factory()->create();

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 100,
        'is_settled' => true,
    ]);

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 150,
        'is_settled' => false,
    ]);

    $rows = app(CustomerStatementService::class)->buildInvoiceRows($customer, [
        'outstandingOnly' => true,
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total_value'])->toBe(150.0);
});

test('buildInvoiceRows applies minimum outstanding balance filter', function () {
    $customer = Customer::factory()->create();

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 50,
    ]);

    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 500,
    ]);

    $rows = app(CustomerStatementService::class)->buildInvoiceRows($customer, [
        'minBalance' => 100,
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total_value'])->toBe(500.0);
});

test('agingBuckets sums outstanding amounts into the correct month bucket', function () {
    $lastMonthLabel = now()->subMonthNoOverflow()->format('F');

    $rows = [
        ['doc_date' => now()->subMonthNoOverflow()->format('d M Y'), 'outstanding' => 75.0],
    ];

    $aging = app(CustomerStatementService::class)->agingBuckets($rows);

    expect($aging['labels'][$lastMonthLabel])->toBe(75.0)
        ->and($aging['total'])->toBe(75.0);
});

test('buildStatementRows includes credit notes and payments without double-counting the aging total', function () {
    $customer = Customer::factory()->create();
    $service = app(CustomerStatementService::class);

    $invoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'doc_date' => now()->subMonthNoOverflow(),
        'total_value' => 300,
    ]);

    Document::factory()->creditNote()->create([
        'customer_id' => $customer->id,
        'doc_date' => now()->subMonthNoOverflow(),
        'total_value' => 50,
        'credited_invoice_id' => $invoice->id,
    ]);

    Payment::factory()->create([
        'customer_id' => $customer->id,
        'payment_date' => now()->subMonthNoOverflow(),
        'amount' => 100,
    ]);

    $invoiceOnlyRows = $service->buildStatementRows($customer, ['includeInvoices' => true]);
    $invoiceOnlyAging = $service->agingBuckets($invoiceOnlyRows);

    $allTypesRows = $service->buildStatementRows($customer, [
        'includeInvoices' => true,
        'includeCreditNotes' => true,
        'includePayments' => true,
    ]);
    $allTypesAging = $service->agingBuckets($allTypesRows);

    expect($allTypesRows)->toHaveCount(3)
        ->and($invoiceOnlyRows)->toHaveCount(1)
        ->and($allTypesAging['total'])->toBe($invoiceOnlyAging['total']);
});
