<?php

use App\Jobs\ExportCustomerOutstandingReportJob;
use App\Models\Customer;
use App\Models\Document;
use App\Models\ExportJob;
use App\Models\User;
use App\Services\CustomerOutstandingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('csv export dispatches a queued job and redirects to the exports page', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Export Co']);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 42, 'doc_date' => now()]);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'csv']));

    $response->assertRedirect(route('exports.index'));

    expect(ExportJob::query()->where('format', 'csv')->where('created_by', $user->id)->exists())->toBeTrue();
    Queue::assertPushed(ExportCustomerOutstandingReportJob::class);
});

test('xlsx export dispatches a queued job', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 15, 'doc_date' => now()]);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'xlsx']));

    $response->assertRedirect(route('exports.index'));
    expect(ExportJob::query()->where('format', 'xlsx')->exists())->toBeTrue();
    Queue::assertPushed(ExportCustomerOutstandingReportJob::class);
});

test('pdf download export dispatches a queued job', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 15, 'doc_date' => now()]);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'pdf']));

    $response->assertRedirect(route('exports.index'));
    expect(ExportJob::query()->where('format', 'pdf')->exists())->toBeTrue();
    Queue::assertPushed(ExportCustomerOutstandingReportJob::class);
});

test('inline pdf preview still streams synchronously', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 15, 'doc_date' => now()]);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'pdf', 'inline' => 1]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect(ExportJob::query()->count())->toBe(0);
});

test('export honors active filters when creating the job', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $matching = Customer::factory()->create(['company_name' => 'Alpha Ltd']);
    $other = Customer::factory()->create(['company_name' => 'Beta Ltd']);
    Document::factory()->invoice()->create(['customer_id' => $matching->id, 'total_value' => 30, 'doc_date' => now()]);
    Document::factory()->invoice()->create(['customer_id' => $other->id, 'total_value' => 40, 'doc_date' => now()]);

    $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'csv', 'search' => 'Alpha']));

    $export = ExportJob::query()->latest()->first();
    expect($export->filters['search'])->toBe('Alpha');
    expect($export->rows_total)->toBe(1);
});

test('pdf export exceeding the row cap is rejected without creating a job', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();

    $service = new class extends CustomerOutstandingReportService
    {
        public function buildExportData(array $filters): array
        {
            return [[
                'company_name' => 'Oversized Co',
                'reference' => 'OS-1',
                'invoices' => array_fill(0, self::PDF_ROW_CAP + 1, [
                    'doc_date' => null,
                    'doc_number' => 'INV-0001',
                    'total_value' => 1.0,
                    'outstanding' => 1.0,
                ]),
            ]];
        }
    };
    app()->instance(CustomerOutstandingReportService::class, $service);

    $response = $this->actingAs($user)->get(route('reports.customer-outstanding-payments.export', ['format' => 'pdf']));

    $response->assertRedirect();
    expect(session('error'))->toContain('exceeds');
    expect(ExportJob::query()->count())->toBe(0);
    Queue::assertNotPushed(ExportCustomerOutstandingReportJob::class);
});
