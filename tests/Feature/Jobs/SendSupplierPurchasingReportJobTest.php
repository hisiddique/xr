<?php

use App\ExportJobStatus;
use App\Jobs\SendSupplierPurchasingReportJob;
use App\Mail\SupplierPurchasingReportMail;
use App\Models\ExportJob;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Services\SupplierPurchasingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
});

test('small reports are attached directly and no per-format export jobs remain running', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'invoice_date' => now(),
    ]);
    SupplierInvoiceItem::factory()->create([
        'supplier_invoice_id' => $invoice->id,
        'line_total' => 25,
        'vat_applicable' => false,
    ]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'supplier_purchasing',
        'format' => 'email',
        'filters' => [],
    ]);

    $job = new SendSupplierPurchasingReportJob($export->id, ['ops@example.com'], ['csv', 'xlsx'], 'note');
    app()->call([$job, 'handle']);

    $export->refresh();
    expect($export->status)->toBe(ExportJobStatus::Completed);

    Mail::assertSent(SupplierPurchasingReportMail::class, function (SupplierPurchasingReportMail $mail) {
        return $mail->hasTo('ops@example.com')
            && count($mail->attachmentsData) === 2
            && $mail->downloadLinks === [];
    });

    $fileExports = ExportJob::where('type', 'supplier_purchasing')->whereIn('format', ['csv', 'xlsx'])->get();
    expect($fileExports)->toHaveCount(2);
    expect($fileExports->every(fn (ExportJob $e) => $e->status === ExportJobStatus::Completed))->toBeTrue();
});

test('oversized reports are sent as download links instead of attachments', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'invoice_date' => now(),
    ]);
    SupplierInvoiceItem::factory()->create([
        'supplier_invoice_id' => $invoice->id,
        'line_total' => 25,
        'vat_applicable' => false,
    ]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'supplier_purchasing',
        'format' => 'email',
        'filters' => [],
    ]);

    $service = new class extends SupplierPurchasingReportService
    {
        public function generateExportFile(string $format, array $filters, string $absPath, ?callable $onChunk = null): array
        {
            file_put_contents($absPath, str_repeat('x', 16 * 1024 * 1024));

            return ['invoiceCount' => 1, 'totalNet' => 25.0, 'totalVat' => 0.0, 'totalGross' => 25.0];
        }
    };
    app()->instance(SupplierPurchasingReportService::class, $service);

    $job = new SendSupplierPurchasingReportJob($export->id, ['ops@example.com'], ['pdf'], null);
    app()->call([$job, 'handle']);

    $export->refresh();
    expect($export->status)->toBe(ExportJobStatus::Completed);

    Mail::assertSent(SupplierPurchasingReportMail::class, function (SupplierPurchasingReportMail $mail) {
        return $mail->attachmentsData === []
            && count($mail->downloadLinks) === 1
            && $mail->downloadLinks[0]['format'] === 'PDF';
    });
});

test('cancelling before it starts skips generation entirely', function () {
    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'supplier_purchasing',
        'format' => 'email',
        'filters' => [],
        'cancelled_at' => now(),
    ]);

    $job = new SendSupplierPurchasingReportJob($export->id, ['ops@example.com'], ['csv'], null);
    app()->call([$job, 'handle']);

    $export->refresh();
    expect($export->status)->toBe(ExportJobStatus::Cancelled);
    Mail::assertNothingSent();
});

test('a failing format marks its own export job failed and fails the send', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'invoice_date' => now(),
    ]);
    SupplierInvoiceItem::factory()->create([
        'supplier_invoice_id' => $invoice->id,
        'line_total' => 25,
        'vat_applicable' => false,
    ]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'supplier_purchasing',
        'format' => 'email',
        'filters' => [],
    ]);

    $service = new class extends SupplierPurchasingReportService
    {
        public function generateExportFile(string $format, array $filters, string $absPath, ?callable $onChunk = null): array
        {
            throw new RuntimeException('boom');
        }
    };
    app()->instance(SupplierPurchasingReportService::class, $service);

    $job = new SendSupplierPurchasingReportJob($export->id, ['ops@example.com'], ['csv'], null);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'boom');

    $export->refresh();
    expect($export->status)->toBe(ExportJobStatus::Failed);
    Mail::assertNothingSent();
});
