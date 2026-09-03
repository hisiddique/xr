<?php

use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Services\SupplierPurchasingReportService;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Csv\Reader;

uses(RefreshDatabase::class);

function makeSupplierPurchasingTestInvoice(Supplier $supplier, float $lineTotal, bool $vatApplicable = false, string $status = 'posted'): SupplierInvoice
{
    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => $status,
        'invoice_date' => now(),
    ]);

    SupplierInvoiceItem::factory()->create([
        'supplier_invoice_id' => $invoice->id,
        'line_total' => $lineTotal,
        'vat_applicable' => $vatApplicable,
    ]);

    return $invoice->fresh(['items', 'payoutAllocations', 'debitNotes']);
}

test('report service returns only posted supplier invoices', function () {
    $supplier = Supplier::factory()->create();
    $posted = makeSupplierPurchasingTestInvoice($supplier, 100);
    makeSupplierPurchasingTestInvoice($supplier, 50, status: 'draft');

    $service = app(SupplierPurchasingReportService::class);
    $results = $service->buildExportData([]);

    expect($results)->toHaveCount(1);
    expect($results[0]['invoices'])->toHaveCount(1);
    expect($results[0]['invoices'][0]['supplier_invoice_no'])->toBe($posted->supplier_invoice_no);
});

test('paidStatus and outstandingAmount reflect payout allocations, debit notes and partial payments', function () {
    $supplier = Supplier::factory()->create();
    $service = app(SupplierPurchasingReportService::class);

    $fullyPaidInvoice = makeSupplierPurchasingTestInvoice($supplier, 100);
    $payout = SupplierPayout::create([
        'supplier_id' => $supplier->id,
        'amount' => 100,
        'payout_date' => now(),
    ]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $fullyPaidInvoice->id,
        'allocated_amount' => 100,
    ]);
    $fullyPaidInvoice->refresh()->load(['items', 'payoutAllocations', 'debitNotes']);

    $debitClearedInvoice = makeSupplierPurchasingTestInvoice($supplier, 80);
    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'subtotal' => 80,
        'vat_amount' => 0,
        'total' => 80,
        'status' => SupplierDebitNoteStatus::Committed,
    ]);
    $debitClearedInvoice->debitNotes()->attach($debitNote->id, ['applied_amount' => 80, 'applied_at' => now()]);
    $debitClearedInvoice->refresh()->load(['items', 'payoutAllocations', 'debitNotes']);

    $partiallyPaidInvoice = makeSupplierPurchasingTestInvoice($supplier, 100);
    $partialPayout = SupplierPayout::create([
        'supplier_id' => $supplier->id,
        'amount' => 40,
        'payout_date' => now(),
    ]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $partialPayout->id,
        'supplier_invoice_id' => $partiallyPaidInvoice->id,
        'allocated_amount' => 40,
    ]);
    $partiallyPaidInvoice->refresh()->load(['items', 'payoutAllocations', 'debitNotes']);

    $unpaidInvoice = makeSupplierPurchasingTestInvoice($supplier, 60);

    expect($service->paidStatus($fullyPaidInvoice))->toBe('paid');
    expect($service->outstandingAmount($fullyPaidInvoice))->toBe(0.0);

    expect($service->paidStatus($debitClearedInvoice))->toBe('paid');
    expect($service->outstandingAmount($debitClearedInvoice))->toBe(0.0);

    expect($service->paidStatus($partiallyPaidInvoice))->toBe('partial');
    expect($service->outstandingAmount($partiallyPaidInvoice))->toBe(60.0);

    expect($service->paidStatus($unpaidInvoice))->toBe('unpaid');
    expect($service->outstandingAmount($unpaidInvoice))->toBe(60.0);
});

test('report service applies amountMin/amountMax range filter on gross even when passed as strings', function () {
    $supplier = Supplier::factory()->create();
    makeSupplierPurchasingTestInvoice($supplier, 40);
    makeSupplierPurchasingTestInvoice($supplier, 100);

    $service = app(SupplierPurchasingReportService::class);

    expect($service->buildExportData(['amountMin' => '50']))->toHaveCount(1);
    expect($service->buildExportData(['amountMin' => '200']))->toHaveCount(0);
    expect($service->buildExportData(['amountMax' => '50']))->toHaveCount(1);
    expect($service->buildExportData(['amountMin' => '30', 'amountMax' => '50']))->toHaveCount(1);
});

test('report service paidStatus filter returns only the matching subset', function () {
    $supplier = Supplier::factory()->create();

    $paidInvoice = makeSupplierPurchasingTestInvoice($supplier, 100);
    $payout = SupplierPayout::create(['supplier_id' => $supplier->id, 'amount' => 100, 'payout_date' => now()]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $paidInvoice->id,
        'allocated_amount' => 100,
    ]);

    $partialInvoice = makeSupplierPurchasingTestInvoice($supplier, 100);
    $partialPayout = SupplierPayout::create(['supplier_id' => $supplier->id, 'amount' => 40, 'payout_date' => now()]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $partialPayout->id,
        'supplier_invoice_id' => $partialInvoice->id,
        'allocated_amount' => 40,
    ]);

    $unpaidInvoice = makeSupplierPurchasingTestInvoice($supplier, 60);

    $service = app(SupplierPurchasingReportService::class);

    $paidNumbers = array_column($service->buildExportData(['paidStatus' => 'paid'])[0]['invoices'], 'supplier_invoice_no');
    expect($paidNumbers)->toBe([$paidInvoice->supplier_invoice_no]);

    $partialNumbers = array_column($service->buildExportData(['paidStatus' => 'partial'])[0]['invoices'], 'supplier_invoice_no');
    expect($partialNumbers)->toBe([$partialInvoice->supplier_invoice_no]);

    $unpaidNumbers = array_column($service->buildExportData(['paidStatus' => 'unpaid'])[0]['invoices'], 'supplier_invoice_no');
    expect($unpaidNumbers)->toBe([$unpaidInvoice->supplier_invoice_no]);
});

test('report service paidStatuses filter accepts multiple statuses and unions the matches', function () {
    $supplier = Supplier::factory()->create();

    $paidInvoice = makeSupplierPurchasingTestInvoice($supplier, 100);
    $payout = SupplierPayout::create(['supplier_id' => $supplier->id, 'amount' => 100, 'payout_date' => now()]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $paidInvoice->id,
        'allocated_amount' => 100,
    ]);

    $partialInvoice = makeSupplierPurchasingTestInvoice($supplier, 100);
    $partialPayout = SupplierPayout::create(['supplier_id' => $supplier->id, 'amount' => 40, 'payout_date' => now()]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $partialPayout->id,
        'supplier_invoice_id' => $partialInvoice->id,
        'allocated_amount' => 40,
    ]);

    $unpaidInvoice = makeSupplierPurchasingTestInvoice($supplier, 60);

    $service = app(SupplierPurchasingReportService::class);

    $union = array_column($service->buildExportData(['paidStatuses' => ['paid', 'partial']])[0]['invoices'], 'supplier_invoice_no');
    sort($union);
    $expected = [$paidInvoice->supplier_invoice_no, $partialInvoice->supplier_invoice_no];
    sort($expected);
    expect($union)->toBe($expected);

    expect($service->buildExportData(['paidStatuses' => []])[0]['invoices'])->toHaveCount(3);
});

test('report service search matches company name, reference, supplier invoice number and supplier ref invoice number', function () {
    $supplier = Supplier::factory()->create(['company_name' => 'Acme Traders']);
    $invoice = makeSupplierPurchasingTestInvoice($supplier, 10);
    $invoice->update(['supplier_ref_invoice_no' => 'REF-XYZ-99']);

    $service = app(SupplierPurchasingReportService::class);

    expect($service->buildExportData(['search' => 'Acme']))->toHaveCount(1);
    expect($service->buildExportData(['search' => $supplier->reference]))->toHaveCount(1);
    expect($service->buildExportData(['search' => $invoice->supplier_invoice_no]))->toHaveCount(1);
    expect($service->buildExportData(['search' => 'REF-XYZ-99']))->toHaveCount(1);
    expect($service->buildExportData(['search' => 'no-match-xyz']))->toHaveCount(0);
});

test('report service applies dateFrom/dateTo range filter on invoice_date', function () {
    $supplier = Supplier::factory()->create();

    $inRange = makeSupplierPurchasingTestInvoice($supplier, 10);
    $inRange->update(['invoice_date' => '2026-06-15']);

    $outOfRange = makeSupplierPurchasingTestInvoice($supplier, 20);
    $outOfRange->update(['invoice_date' => '2026-01-01']);

    $service = app(SupplierPurchasingReportService::class);

    $results = $service->buildExportData(['dateFrom' => '2026-06-01', 'dateTo' => '2026-06-30']);
    $numbers = array_column($results[0]['invoices'], 'supplier_invoice_no');
    expect($numbers)->toBe([$inRange->supplier_invoice_no]);
});

test('exportChunks yields suppliers ordered by company_name then id', function () {
    $supplierA = Supplier::factory()->create(['company_name' => 'Duplicate Co']);
    $supplierB = Supplier::factory()->create(['company_name' => 'Duplicate Co']);

    makeSupplierPurchasingTestInvoice($supplierA, 10);
    makeSupplierPurchasingTestInvoice($supplierB, 20);

    [$first, $second] = min($supplierA->id, $supplierB->id) === $supplierA->id
        ? [$supplierA, $supplierB]
        : [$supplierB, $supplierA];

    $service = app(SupplierPurchasingReportService::class);
    $results = iterator_to_array($service->exportChunks([]));

    expect($results)->toHaveCount(2);
    expect($results[0]['reference'])->toBe((string) $first->reference);
    expect($results[1]['reference'])->toBe((string) $second->reference);
});

test('exportChunks yields every supplier across a chunk boundary without duplicates', function () {
    $count = 205;

    $suppliers = Supplier::factory()->count($count)->create();

    foreach ($suppliers as $supplier) {
        makeSupplierPurchasingTestInvoice($supplier, 10);
    }

    $service = app(SupplierPurchasingReportService::class);
    $results = iterator_to_array($service->exportChunks([]));

    expect($results)->toHaveCount($count);

    $references = array_column($results, 'reference');
    expect(count(array_unique($references)))->toBe($count);

    $names = array_column($results, 'company_name');
    $sortedNames = $names;
    sort($sortedNames, SORT_STRING);
    expect($names)->toBe($sortedNames);
});

test('buildExportData returns the documented shape', function () {
    $supplier = Supplier::factory()->create();
    $invoice = makeSupplierPurchasingTestInvoice($supplier, 50, vatApplicable: true);
    $invoice->update(['supplier_ref_invoice_no' => 'SREF-1']);

    $service = app(SupplierPurchasingReportService::class);
    $results = $service->buildExportData([]);

    expect($results)->toHaveCount(1);
    expect($results[0])->toHaveKeys(['company_name', 'reference', 'invoices']);
    expect($results[0]['company_name'])->toBe($supplier->company_name);
    expect($results[0]['reference'])->toBe((string) $supplier->reference);

    $invoiceRow = $results[0]['invoices'][0];
    expect($invoiceRow)->toHaveKeys(['invoice_date', 'supplier_invoice_no', 'supplier_ref_invoice_no', 'net', 'vat', 'gross', 'debit_note_ref', 'deductions', 'net_payable', 'paid_status']);
    expect($invoiceRow['supplier_invoice_no'])->toBe($invoice->supplier_invoice_no);
    expect($invoiceRow['supplier_ref_invoice_no'])->toBe('SREF-1');
    expect($invoiceRow['net'])->toBe(50.0);
    expect($invoiceRow['vat'])->toBe(10.0);
    expect($invoiceRow['gross'])->toBe(60.0);
    expect($invoiceRow['debit_note_ref'])->toBe('');
    expect($invoiceRow['deductions'])->toBe(0.0);
    expect($invoiceRow['net_payable'])->toBe(60.0);
    expect($invoiceRow['paid_status'])->toBe('unpaid');
});

test('buildExportData surfaces applied debit-note deductions and net payable', function () {
    $supplier = Supplier::factory()->create();
    $invoice = makeSupplierPurchasingTestInvoice($supplier, 100, vatApplicable: true); // gross 120

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'subtotal' => 20,
        'vat_amount' => 0,
        'total' => 20,
        'status' => SupplierDebitNoteStatus::Committed,
    ]);
    $invoice->debitNotes()->attach($debitNote->id, ['applied_amount' => 20, 'applied_at' => now()]);

    $service = app(SupplierPurchasingReportService::class);
    $row = $service->buildExportData([])[0]['invoices'][0];

    expect($row['debit_note_ref'])->toBe($debitNote->reference);
    expect($row['deductions'])->toBe(20.0);
    expect($row['gross'])->toBe(120.0);
    expect($row['net_payable'])->toBe(100.0);
});

test('writeCsvToPath and writeXlsxToPath produce rows aligned under the export headings', function () {
    $supplier = Supplier::factory()->create(['company_name' => 'Aligned Co', 'reference' => 'REF-1']);
    $invoice = makeSupplierPurchasingTestInvoice($supplier, 50, vatApplicable: true);

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'subtotal' => 15,
        'vat_amount' => 0,
        'total' => 15,
        'status' => SupplierDebitNoteStatus::Committed,
    ]);
    $invoice->debitNotes()->attach($debitNote->id, ['applied_amount' => 15, 'applied_at' => now()]);

    $service = app(SupplierPurchasingReportService::class);

    $csvPath = tempnam(sys_get_temp_dir(), 'sup-csv-align-test');
    $xlsxPath = tempnam(sys_get_temp_dir(), 'sup-xlsx-align-test');

    try {
        $service->writeCsvToPath($csvPath, $service->exportChunks([]));
        $records = Reader::createFromPath($csvPath)->getRecords();
        $rows = iterator_to_array($records, false);

        expect($rows[0])->toBe(['Supplier', 'Reference', 'Date', 'Supplier Invoice No', 'Invoice', 'Net', 'VAT', 'Gross', 'Debit Note', 'Deductions', 'Net Payable', 'Status']);
        expect(count($rows[1]))->toBe(12);
        expect($rows[1][0])->toBe('Aligned Co');
        expect($rows[1][1])->toBe('REF-1');
        expect($rows[1][3])->toBe($invoice->supplier_invoice_no);
        expect($rows[1][5])->toBe('50.00');
        expect($rows[1][6])->toBe('10.00');
        expect($rows[1][7])->toBe('60.00');
        expect($rows[1][8])->toBe($debitNote->reference);
        expect($rows[1][9])->toBe('-15.00');
        expect($rows[1][10])->toBe('45.00');
        expect($rows[1][11])->toBe('Partial');
        expect(count($rows[2]))->toBe(12);
        expect($rows[2][7])->toBe('60.00');
        expect($rows[2][9])->toBe('-15.00');
        expect($rows[2][10])->toBe('45.00');

        $service->writeXlsxToPath($xlsxPath, $service->exportChunks([]));
        $reader = new OpenSpout\Reader\XLSX\Reader;
        $reader->open($xlsxPath);
        $xlsxRows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $xlsxRows[] = $row->toArray();
            }
        }
        $reader->close();

        expect($xlsxRows[0])->toBe(['Supplier', 'Reference', 'Date', 'Supplier Invoice No', 'Invoice', 'Net', 'VAT', 'Gross', 'Debit Note', 'Deductions', 'Net Payable', 'Status']);
        expect(count($xlsxRows[1]))->toBe(12);
        expect($xlsxRows[1][0])->toBe('Aligned Co');
        expect($xlsxRows[1][3])->toBe($invoice->supplier_invoice_no);
        expect($xlsxRows[1][7])->toBe('60.00');
        expect($xlsxRows[1][9])->toBe('-15.00');
        expect($xlsxRows[1][10])->toBe('45.00');
    } finally {
        @unlink($csvPath);
        @unlink($xlsxPath);
    }
});

test('summary aggregates invoiceCount, totals, deductions and net payable across filtered posted invoices', function () {
    $supplier = Supplier::factory()->create();
    $withDebit = makeSupplierPurchasingTestInvoice($supplier, 100, vatApplicable: true);
    makeSupplierPurchasingTestInvoice($supplier, 50, vatApplicable: false);
    makeSupplierPurchasingTestInvoice($supplier, 999, status: 'draft');

    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'subtotal' => 30,
        'vat_amount' => 0,
        'total' => 30,
        'status' => SupplierDebitNoteStatus::Committed,
    ]);
    $withDebit->debitNotes()->attach($debitNote->id, ['applied_amount' => 30, 'applied_at' => now()]);

    $service = app(SupplierPurchasingReportService::class);
    $summary = $service->summary([]);

    expect($summary['invoiceCount'])->toBe(2);
    expect($summary['totalNet'])->toBe(150.0);
    expect($summary['totalVat'])->toBe(20.0);
    expect($summary['totalGross'])->toBe(170.0);
    expect($summary['totalDeductions'])->toBe(30.0);
    expect($summary['totalNetPayable'])->toBe(140.0);
});

test('summary totalNetPayable clamps per invoice so it equals the sum of row net payables', function () {
    $supplier = Supplier::factory()->create();
    $overDeducted = makeSupplierPurchasingTestInvoice($supplier, 100); // gross 100, no vat
    makeSupplierPurchasingTestInvoice($supplier, 100); // gross 100

    // Debit note larger than the invoice gross — excess must not bleed into the other invoice.
    $debitNote = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'subtotal' => 150,
        'vat_amount' => 0,
        'total' => 150,
        'status' => SupplierDebitNoteStatus::Committed,
    ]);
    $overDeducted->debitNotes()->attach($debitNote->id, ['applied_amount' => 150, 'applied_at' => now()]);

    $service = app(SupplierPurchasingReportService::class);

    $rowNetPayableSum = array_sum(array_column($service->buildExportData([])[0]['invoices'], 'net_payable'));

    expect($service->summary([])['totalNetPayable'])->toBe($rowNetPayableSum)
        ->and($rowNetPayableSum)->toBe(100.0); // 0 (clamped) + 100, not max(0, 200 - 150) = 50
});
