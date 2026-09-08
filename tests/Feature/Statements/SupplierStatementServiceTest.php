<?php

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Services\SupplierStatementService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function supplierInvoiceWorth(Supplier $supplier, float $amount, string $date): SupplierInvoice
{
    return SupplierInvoice::factory()
        ->for($supplier)
        ->has(
            SupplierInvoiceItem::factory()->state([
                'quantity' => 1,
                'unit_amount' => $amount,
                'vat_applicable' => false,
                'line_total' => $amount,
            ]),
            'items',
        )
        ->create(['invoice_date' => $date]);
}

test('buildInvoiceRows filters supplier invoices by date range', function () {
    $supplier = Supplier::factory()->create();
    supplierInvoiceWorth($supplier, 100, '2026-01-15');
    supplierInvoiceWorth($supplier, 200, '2026-03-15');

    $rows = app(SupplierStatementService::class)->buildInvoiceRows($supplier, [
        'dateFrom' => '2026-03-01',
        'dateTo' => '2026-03-31',
    ]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['total_value'])->toBe(200.0)
        ->and($rows[0]['outstanding'])->toBe(200.0);
});

test('buildInvoiceRows honours outstandingOnly and minBalance', function () {
    $supplier = Supplier::factory()->create();
    supplierInvoiceWorth($supplier, 40, '2026-02-01');
    supplierInvoiceWorth($supplier, 500, '2026-02-02');

    $service = app(SupplierStatementService::class);

    expect($service->buildInvoiceRows($supplier, ['minBalance' => 100]))->toHaveCount(1)
        ->and($service->buildInvoiceRows($supplier, ['outstandingOnly' => true]))->toHaveCount(2);
});

test('agingBuckets honours asOf for supplier statements', function () {
    $rows = [
        ['doc_date' => Carbon::parse('2026-03-10')->format('d M Y'), 'outstanding' => 60.0],
    ];

    $service = app(SupplierStatementService::class);

    expect($service->agingBuckets($rows, Carbon::parse('2026-03-20'))['total'])->toBe(0.0)
        ->and($service->agingBuckets($rows, Carbon::parse('2026-04-05'))['labels']['March'])->toBe(60.0);
});

test('buildStatementRows returns invoice rows and can render a PDF', function () {
    $supplier = Supplier::factory()->create();
    supplierInvoiceWorth($supplier, 300, now()->subMonthNoOverflow()->toDateString());

    $service = app(SupplierStatementService::class);
    $rows = $service->buildStatementRows($supplier, ['includeInvoices' => true]);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['row_type'])->toBe('invoice');

    $pdf = $service->pdfBinary($supplier, ['includeInvoices' => true]);
    expect($pdf)->toStartWith('%PDF');
});
