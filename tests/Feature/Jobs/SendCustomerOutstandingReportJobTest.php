<?php

use App\ExportJobStatus;
use App\Jobs\SendCustomerOutstandingReportJob;
use App\Mail\CustomerOutstandingReportMail;
use App\Models\Customer;
use App\Models\Document;
use App\Models\ExportJob;
use App\Services\CustomerOutstandingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Mail::fake();
});

test('small reports are attached directly and no per-format export jobs remain running', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 25, 'doc_date' => now()]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'email',
        'filters' => [],
    ]);

    $job = new SendCustomerOutstandingReportJob($export->id, ['ops@example.com'], ['csv', 'xlsx'], 'note');
    app()->call([$job, 'handle']);

    $export->refresh();
    expect($export->status)->toBe(ExportJobStatus::Completed);

    Mail::assertSent(CustomerOutstandingReportMail::class, function (CustomerOutstandingReportMail $mail) {
        return $mail->hasTo('ops@example.com')
            && count($mail->attachmentsData) === 2
            && $mail->downloadLinks === [];
    });

    $fileExports = ExportJob::where('type', 'customer_outstanding_payments')->whereIn('format', ['csv', 'xlsx'])->get();
    expect($fileExports)->toHaveCount(2);
    expect($fileExports->every(fn (ExportJob $e) => $e->status === ExportJobStatus::Completed))->toBeTrue();
});

test('oversized reports are sent as download links instead of attachments', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 25, 'doc_date' => now()]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'email',
        'filters' => [],
    ]);

    $service = new class extends CustomerOutstandingReportService
    {
        public function generateExportFile(string $format, array $filters, string $absPath, ?callable $onChunk = null): array
        {
            // Simulate a file that exceeds the attachment size cap without
            // actually generating tens of thousands of report rows.
            file_put_contents($absPath, str_repeat('x', 16 * 1024 * 1024));

            return ['customerCount' => 1, 'totalOutstanding' => 25.0];
        }
    };
    app()->instance(CustomerOutstandingReportService::class, $service);

    $job = new SendCustomerOutstandingReportJob($export->id, ['ops@example.com'], ['pdf'], null);
    app()->call([$job, 'handle']);

    $export->refresh();
    expect($export->status)->toBe(ExportJobStatus::Completed);

    Mail::assertSent(CustomerOutstandingReportMail::class, function (CustomerOutstandingReportMail $mail) {
        return $mail->attachmentsData === []
            && count($mail->downloadLinks) === 1
            && $mail->downloadLinks[0]['format'] === 'PDF';
    });
});

test('cancelling before it starts skips generation entirely', function () {
    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'email',
        'filters' => [],
        'cancelled_at' => now(),
    ]);

    $job = new SendCustomerOutstandingReportJob($export->id, ['ops@example.com'], ['csv'], null);
    app()->call([$job, 'handle']);

    $export->refresh();
    expect($export->status)->toBe(ExportJobStatus::Cancelled);
    Mail::assertNothingSent();
});

test('a failing format marks its own export job failed and fails the send', function () {
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 25, 'doc_date' => now()]);

    $export = ExportJob::create([
        'status' => ExportJobStatus::Pending,
        'type' => 'customer_outstanding_payments',
        'format' => 'email',
        'filters' => [],
    ]);

    $service = new class extends CustomerOutstandingReportService
    {
        public function generateExportFile(string $format, array $filters, string $absPath, ?callable $onChunk = null): array
        {
            throw new RuntimeException('boom');
        }
    };
    app()->instance(CustomerOutstandingReportService::class, $service);

    $job = new SendCustomerOutstandingReportJob($export->id, ['ops@example.com'], ['csv'], null);

    expect(fn () => app()->call([$job, 'handle']))->toThrow(RuntimeException::class, 'boom');

    $export->refresh();
    expect($export->status)->toBe(ExportJobStatus::Failed);
    Mail::assertNothingSent();
});
