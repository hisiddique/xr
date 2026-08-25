<?php

use App\Jobs\SendSupplierPurchasingReportJob;
use App\Models\ExportJob;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeSupplierPurchasingPageInvoice(Supplier $supplier, float $lineTotal): SupplierInvoice
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

test('report page is accessible and lists posted invoices grouped by supplier', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $supplier = Supplier::factory()->create(['company_name' => 'Purchasing Co']);
    makeSupplierPurchasingPageInvoice($supplier, 75);

    $this->actingAs($user)->get(route('reports.supplier-purchasing'))->assertOk();

    Livewire::actingAs($user)
        ->test('pages::reports.supplier-purchasing')
        ->assertSeeText('Purchasing Co')
        ->assertSeeText('75.00');
});

test('report page search filters by supplier name', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $matching = Supplier::factory()->create(['company_name' => 'Zeta Traders']);
    $other = Supplier::factory()->create(['company_name' => 'Omega Supplies']);
    makeSupplierPurchasingPageInvoice($matching, 30);
    makeSupplierPurchasingPageInvoice($other, 40);

    Livewire::actingAs($user)
        ->test('pages::reports.supplier-purchasing')
        ->set('search', 'Zeta')
        ->assertSeeText('Zeta Traders')
        ->assertDontSeeText('Omega Supplies');
});

test('sending the report requires at least one recipient and at least one format', function () {
    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $supplier = Supplier::factory()->create();
    makeSupplierPurchasingPageInvoice($supplier, 20);

    Livewire::actingAs($user)
        ->test('pages::reports.supplier-purchasing')
        ->set('reportEmails', [])
        ->set('reportFormats', [])
        ->call('sendReportEmail')
        ->assertHasErrors(['reportEmails', 'reportFormats']);
});

test('sending the report queues a job instead of sending inline', function () {
    Queue::fake();

    $user = User::factory()->staff()->create(['email_verified_at' => now()]);
    $supplier = Supplier::factory()->create();
    makeSupplierPurchasingPageInvoice($supplier, 20);

    Livewire::actingAs($user)
        ->test('pages::reports.supplier-purchasing')
        ->set('reportEmails', ['ops@example.com'])
        ->set('reportFormats', ['pdf', 'csv'])
        ->call('sendReportEmail')
        ->assertHasNoErrors();

    expect(ExportJob::query()->where('format', 'email')->where('created_by', $user->id)->exists())->toBeTrue();

    Queue::assertPushed(SendSupplierPurchasingReportJob::class);
});
