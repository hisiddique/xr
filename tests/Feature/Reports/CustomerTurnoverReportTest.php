<?php

use App\Jobs\ExportCustomerTurnoverReportJob;
use App\Models\Customer;
use App\Models\Document;
use App\Models\ExportJob;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\CustomerTurnoverReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

uses(RefreshDatabase::class);

afterEach(function () {
    Carbon::setTestNow();
});

function turnoverRows(Testable $component): Collection
{
    return collect($component->instance()->rows->items());
}

test('the report page is gated on the report-customerTurnover permission', function () {
    $forbidden = User::factory()->noRoles()->create(['email_verified_at' => now()]);
    $this->actingAs($forbidden)->get(route('reports.customer-turnover'))->assertForbidden();

    $allowed = User::factory()->staff()->create(['email_verified_at' => now()]);
    $this->actingAs($allowed)->get(route('reports.customer-turnover'))->assertOk();
});

test('turnover total and invoice count are scoped to the selected period', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Alpha Ltd']);

    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 100, 'doc_date' => '2026-06-05']);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 200, 'doc_date' => '2026-06-20']);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 500, 'doc_date' => '2025-03-01']);

    $component = Livewire::actingAs($user)->test('pages::reports.customer-turnover');

    $thisMonth = turnoverRows($component)->firstWhere('id', $customer->id);
    expect((int) $thisMonth->invoice_count)->toBe(2)
        ->and((float) $thisMonth->total)->toBe(300.0);

    $component->set('preset', 'last_year');

    $lastYear = turnoverRows($component)->firstWhere('id', $customer->id);
    expect((int) $lastYear->invoice_count)->toBe(1)
        ->and((float) $lastYear->total)->toBe(500.0);
});

test('customers with no invoices in the period are excluded', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $active = Customer::factory()->create(['company_name' => 'Active Co']);
    $dormant = Customer::factory()->create(['company_name' => 'Dormant Co']);

    Document::factory()->invoice()->create(['customer_id' => $active->id, 'total_value' => 120, 'doc_date' => '2026-06-10']);
    Document::factory()->invoice()->create(['customer_id' => $dormant->id, 'total_value' => 999, 'doc_date' => '2025-06-10']);

    $component = Livewire::actingAs($user)->test('pages::reports.customer-turnover');

    $ids = turnoverRows($component)->pluck('id')->all();
    expect($ids)->toContain($active->id)
        ->and($ids)->not->toContain($dormant->id);
});

test('the default sort is total descending and sortBy flips it to ascending', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $small = Customer::factory()->create(['company_name' => 'Alpha Ltd']);
    $large = Customer::factory()->create(['company_name' => 'Charlie Ltd']);

    Document::factory()->invoice()->create(['customer_id' => $small->id, 'total_value' => 300, 'doc_date' => '2026-06-10']);
    Document::factory()->invoice()->create(['customer_id' => $large->id, 'total_value' => 1000, 'doc_date' => '2026-06-10']);

    $component = Livewire::actingAs($user)->test('pages::reports.customer-turnover');

    expect(turnoverRows($component)->pluck('id')->all())->toBe([$large->id, $small->id]);

    $component->call('sortBy', 'total');

    expect(turnoverRows($component)->pluck('id')->all())->toBe([$small->id, $large->id]);
});

test('includeOutstanding adds a period-scoped outstanding column', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Balance Co']);

    $periodInvoice = Document::factory()->invoice()->create([
        'customer_id' => $customer->id, 'total_value' => 200, 'doc_date' => '2026-06-10',
    ]);
    Document::factory()->invoice()->create([
        'customer_id' => $customer->id, 'total_value' => 500, 'doc_date' => '2025-03-01',
    ]);

    $method = LookupPaymentMethod::factory()->create();
    $payment = Payment::factory()->create([
        'customer_id' => $customer->id, 'payment_method_id' => $method->id, 'amount' => 50,
    ]);
    PaymentAllocation::create([
        'payment_id' => $payment->id, 'document_id' => $periodInvoice->id, 'allocated_amount' => 50,
    ]);

    $component = Livewire::actingAs($user)->test('pages::reports.customer-turnover');

    expect(turnoverRows($component)->firstWhere('id', $customer->id)->outstanding)->toBeNull();

    $component->set('includeOutstanding', true);

    $row = turnoverRows($component)->firstWhere('id', $customer->id);
    expect((float) $row->total)->toBe(200.0)
        ->and((float) $row->outstanding)->toBe(150.0);
});

test('totalMin and totalMax filter customers by their period turnover', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $small = Customer::factory()->create(['company_name' => 'Small Co']);
    $large = Customer::factory()->create(['company_name' => 'Large Co']);

    Document::factory()->invoice()->create(['customer_id' => $small->id, 'total_value' => 300, 'doc_date' => '2026-06-10']);
    Document::factory()->invoice()->create(['customer_id' => $large->id, 'total_value' => 1000, 'doc_date' => '2026-06-10']);

    $component = Livewire::actingAs($user)->test('pages::reports.customer-turnover');

    $component->set('totalMin', '500');
    expect(turnoverRows($component)->pluck('id')->all())->toBe([$large->id]);

    $component->set('totalMin', '')->set('totalMax', '500');
    expect(turnoverRows($component)->pluck('id')->all())->toBe([$small->id]);
});

test('export headings follow the includeOutstanding flag', function () {
    $service = app(CustomerTurnoverReportService::class);

    expect($service->exportHeadings(['includeOutstanding' => false]))
        ->toBe(['Customer', 'Reference', 'Invoices', 'Total']);

    expect($service->exportHeadings(['includeOutstanding' => true]))
        ->toBe(['Customer', 'Reference', 'Invoices', 'Total', 'Outstanding']);
});

test('csv export queues a customer_turnover job and redirects to the exports page', function () {
    Queue::fake();

    Carbon::setTestNow('2026-06-15 12:00:00');

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create(['company_name' => 'Export Co']);
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 42, 'doc_date' => '2026-06-10']);

    $response = $this->actingAs($user)->get(route('reports.customer-turnover.export', [
        'format' => 'csv', 'includeOutstanding' => 0,
    ]));

    $response->assertRedirect(route('exports.index'));

    expect(ExportJob::query()
        ->where('type', 'customer_turnover')
        ->where('format', 'csv')
        ->where('created_by', $user->id)
        ->exists())->toBeTrue();

    Queue::assertPushed(ExportCustomerTurnoverReportJob::class);
});

test('inline pdf preview streams synchronously without queuing a job', function () {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $customer = Customer::factory()->create();
    Document::factory()->invoice()->create(['customer_id' => $customer->id, 'total_value' => 15, 'doc_date' => '2026-06-10']);

    $response = $this->actingAs($user)->get(route('reports.customer-turnover.export', [
        'format' => 'pdf', 'inline' => 1,
    ]));

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect(ExportJob::query()->count())->toBe(0);
});
