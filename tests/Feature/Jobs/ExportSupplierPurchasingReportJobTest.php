<?php

use App\ExportJobStatus;
use App\Jobs\ExportSupplierPurchasingReportJob;
use App\Models\ExportJob;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Services\SupplierPurchasingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('job writes a csv file and marks the export completed', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'invoice_date' => now(),
    ]);
    SupplierInvoiceItem::factory()->create([
        'supplier_invoice_id' => $invoice->id,
        'line_total' => 30,
        'vat_applicable' => false,
    ]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'supplier_purchasing',
        'format' => 'csv',
        'filters' => [],
        'rows_total' => 1,
    ]);

    app()->call([new ExportSupplierPurchasingReportJob($export->id), 'handle']);

    $export->refresh();

    expect($export->status)->toBe(ExportJobStatus::Completed);
    expect($export->rows_processed)->toBe(1);
    expect($export->download_path)->not->toBeNull();
    expect(Storage::disk('local')->exists($export->download_path))->toBeTrue();
});

test('job skips work and marks cancelled when cancelled before it starts', function () {
    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'supplier_purchasing',
        'format' => 'csv',
        'filters' => [],
        'rows_total' => 0,
        'cancelled_at' => now(),
    ]);

    app()->call([new ExportSupplierPurchasingReportJob($export->id), 'handle']);

    $export->refresh();

    expect($export->status)->toBe(ExportJobStatus::Cancelled);
    expect($export->download_path)->toBeNull();
});

test('job fails a pdf export that exceeds the invoice row cap', function () {
    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'supplier_purchasing',
        'format' => 'pdf',
        'filters' => [],
        'rows_total' => 1,
    ]);

    $service = new class extends SupplierPurchasingReportService
    {
        public function buildExportData(array $filters): array
        {
            return [[
                'company_name' => 'Oversized Co',
                'reference' => 'OS-1',
                'invoices' => array_fill(0, SupplierPurchasingReportService::PDF_ROW_CAP + 1, [
                    'invoice_date' => null,
                    'supplier_invoice_no' => 'SUPINV-0001',
                    'supplier_ref_invoice_no' => '',
                    'net' => 1.0,
                    'vat' => 0.0,
                    'gross' => 1.0,
                    'paid_status' => 'unpaid',
                ]),
            ]];
        }
    };

    app()->instance(SupplierPurchasingReportService::class, $service);

    $job = new ExportSupplierPurchasingReportJob($export->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'exceeds');

    $export->refresh();

    expect($export->status)->toBe(ExportJobStatus::Failed);
    expect($export->error)->toContain('exceeds');
    expect($export->download_path)->toBeNull();
});
