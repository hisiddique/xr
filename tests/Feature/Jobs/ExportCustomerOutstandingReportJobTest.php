<?php

use App\ExportJobStatus;
use App\Jobs\ExportCustomerOutstandingReportJob;
use App\Models\Customer;
use App\Models\Document;
use App\Models\ExportJob;
use App\Services\CustomerOutstandingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
});

test('job writes a csv file and marks the export completed', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create([
        'customer_id' => $customer->id,
        'total_value' => 30,
        'doc_date' => now(),
    ]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'filters' => [],
        'rows_total' => 1,
    ]);

    app()->call([new ExportCustomerOutstandingReportJob($export->id), 'handle']);

    $export->refresh();

    expect($export->status)->toBe(ExportJobStatus::Completed);
    expect($export->rows_processed)->toBe(1);
    expect($export->download_path)->not->toBeNull();
    expect(Storage::disk('local')->exists($export->download_path))->toBeTrue();
});

test('job skips work and marks cancelled when cancelled before it starts', function () {
    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'csv',
        'filters' => [],
        'rows_total' => 0,
        'cancelled_at' => now(),
    ]);

    app()->call([new ExportCustomerOutstandingReportJob($export->id), 'handle']);

    $export->refresh();

    expect($export->status)->toBe(ExportJobStatus::Cancelled);
    expect($export->download_path)->toBeNull();
});

test('job fails a pdf export that exceeds the invoice row cap', function () {
    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'pdf',
        'filters' => [],
        'rows_total' => 1,
    ]);

    $service = new class extends CustomerOutstandingReportService
    {
        public function buildExportData(array $filters): array
        {
            return [[
                'company_name' => 'Oversized Co',
                'reference' => 'OS-1',
                'invoices' => array_fill(0, CustomerOutstandingReportService::PDF_ROW_CAP + 1, [
                    'doc_date' => null,
                    'doc_number' => 'INV-0001',
                    'total_value' => 1.0,
                    'outstanding' => 1.0,
                ]),
            ]];
        }
    };

    app()->instance(CustomerOutstandingReportService::class, $service);

    $job = new ExportCustomerOutstandingReportJob($export->id);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'exceeds');

    $export->refresh();

    expect($export->status)->toBe(ExportJobStatus::Failed);
    expect($export->error)->toContain('exceeds');
    expect($export->download_path)->toBeNull();
});
