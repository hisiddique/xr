<?php

use App\Jobs\ExportSupplierPurchasingReportJob;
use App\Models\ExportJob;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\User;
use App\Services\SupplierPurchasingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function makeSupplierPurchasingExportInvoice(Supplier $supplier, float $lineTotal): SupplierInvoice
{
    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'status' => 'posted',
        'invoice_date' => now(),
    ]);

    SupplierInvoiceItem::factory()->create([
        'supplier_invoice_id' => $invoice->id,
        'line_total' => $lineTotal,
        'vat_applicable' => false,
    ]);

    return $invoice;
}

test('csv export dispatches a queued job and redirects to the exports page', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $supplier = Supplier::factory()->create(['company_name' => 'Export Co']);
    makeSupplierPurchasingExportInvoice($supplier, 42);

    $response = $this->actingAs($user)->get(route('reports.supplier-purchasing.export', ['format' => 'csv']));

    $response->assertRedirect(route('exports.index'));

    expect(ExportJob::query()->where('format', 'csv')->where('created_by', $user->id)->exists())->toBeTrue();
    Queue::assertPushed(ExportSupplierPurchasingReportJob::class);
});

test('xlsx export dispatches a queued job', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $supplier = Supplier::factory()->create();
    makeSupplierPurchasingExportInvoice($supplier, 15);

    $response = $this->actingAs($user)->get(route('reports.supplier-purchasing.export', ['format' => 'xlsx']));

    $response->assertRedirect(route('exports.index'));
    expect(ExportJob::query()->where('format', 'xlsx')->exists())->toBeTrue();
    Queue::assertPushed(ExportSupplierPurchasingReportJob::class);
});

test('pdf download export dispatches a queued job', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $supplier = Supplier::factory()->create();
    makeSupplierPurchasingExportInvoice($supplier, 15);

    $response = $this->actingAs($user)->get(route('reports.supplier-purchasing.export', ['format' => 'pdf']));

    $response->assertRedirect(route('exports.index'));
    expect(ExportJob::query()->where('format', 'pdf')->exists())->toBeTrue();
    Queue::assertPushed(ExportSupplierPurchasingReportJob::class);
});

test('inline pdf preview still streams synchronously', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $supplier = Supplier::factory()->create();
    makeSupplierPurchasingExportInvoice($supplier, 15);

    $response = $this->actingAs($user)->get(route('reports.supplier-purchasing.export', ['format' => 'pdf', 'inline' => 1]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect(ExportJob::query()->count())->toBe(0);
});

test('export honors active filters when creating the job', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $matching = Supplier::factory()->create(['company_name' => 'Alpha Ltd']);
    $other = Supplier::factory()->create(['company_name' => 'Beta Ltd']);
    makeSupplierPurchasingExportInvoice($matching, 30);
    makeSupplierPurchasingExportInvoice($other, 40);

    $this->actingAs($user)->get(route('reports.supplier-purchasing.export', ['format' => 'csv', 'search' => 'Alpha']));

    $export = ExportJob::query()->latest()->first();
    expect($export->filters['search'])->toBe('Alpha');
    expect($export->rows_total)->toBe(1);
});

test('pdf export exceeding the row cap is rejected without creating a job', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    Supplier::factory()->create();

    $service = new class extends SupplierPurchasingReportService
    {
        public function buildExportData(array $filters): array
        {
            return [[
                'company_name' => 'Oversized Co',
                'reference' => 'OS-1',
                'invoices' => array_fill(0, self::PDF_ROW_CAP + 1, [
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

    $response = $this->actingAs($user)->get(route('reports.supplier-purchasing.export', ['format' => 'pdf']));

    $response->assertRedirect();
    expect(session('error'))->toContain('exceeds');
    expect(ExportJob::query()->count())->toBe(0);
    Queue::assertNotPushed(ExportSupplierPurchasingReportJob::class);
});
