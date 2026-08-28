<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use App\Models\User;
use App\SupplierInvoiceStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Setting::updateOrCreate(['key' => 'supinv_prefix'], ['key' => 'supinv_prefix', 'value' => 'SUPINV', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'number_padding'], ['key' => 'number_padding', 'value' => '4', 'type' => 'integer']);
    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => '20', 'type' => 'float']);
    Setting::flushCache();
});

// ── Number generation ───────────────────────────────────────────
test('auto-numbers first invoice as SUPINV-0001', function () {
    $invoice = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);

    expect($invoice->supplier_invoice_no)->toBe('SUPINV-0001');
});

test('increments invoice number sequentially', function () {
    SupplierInvoice::factory()->create(['created_by' => $this->user->id]);
    $second = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);

    expect($second->supplier_invoice_no)->toBe('SUPINV-0002');
});

test('number generation is gap-safe after delete', function () {
    $a = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);
    SupplierInvoice::factory()->create(['created_by' => $this->user->id]);
    $a->delete();

    $third = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);
    expect($third->supplier_invoice_no)->toBe('SUPINV-0003');
});

test('does not reuse the number of a soft-deleted highest invoice', function () {
    $invoice = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);
    $invoice->delete();

    $next = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);

    expect($next->supplier_invoice_no)->toBe('SUPINV-0002');
});

// ── Totals calculation ─────────────────────────────────────────
test('grossTotal adds vat on top of net line totals', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 2, 'unit_amount' => 100, 'vat_applicable' => false, 'line_total' => 200]), 'items')
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 3, 'unit_amount' => 50, 'vat_applicable' => true, 'line_total' => 150]), 'items')
        ->create(['created_by' => $this->user->id]);

    $invoice->load('items');

    expect($invoice->netTotal)->toBe(350.0);
    expect(round($invoice->vatTotal, 2))->toBe(30.0);
    expect($invoice->grossTotal)->toBe(380.0);
});

test('vatTotal applies only to vat_applicable lines', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 120, 'vat_applicable' => true, 'line_total' => 120]), 'items')
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 50, 'vat_applicable' => false, 'line_total' => 50]), 'items')
        ->create(['created_by' => $this->user->id]);

    $invoice->load('items');

    expect(round($invoice->vatTotal, 2))->toBe(24.0);
    expect(round($invoice->netTotal, 2))->toBe(170.0);
    expect($invoice->grossTotal)->toBe(194.0);
});

// ── Payment status ───────────────────────────────────────────
test('isPaid is false until allocations and deductions cover the gross total', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 100, 'vat_applicable' => false, 'line_total' => 100]), 'items')
        ->create(['created_by' => $this->user->id]);

    $reload = fn () => $invoice->fresh(['items', 'payoutAllocations', 'debitNotes']);

    expect($reload()->isPaid())->toBeFalse();

    $payout = SupplierPayout::create([
        'supplier_id' => $invoice->supplier_id,
        'amount' => 100,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);

    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $invoice->id,
        'allocated_amount' => 60,
        'deduction_amount' => 0,
    ]);

    expect($reload()->isPaid())->toBeFalse();

    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $invoice->id,
        'allocated_amount' => 40,
        'deduction_amount' => 0,
    ]);

    expect($reload()->isPaid())->toBeTrue();
});

test('index renders Paid and Unpaid status badges', function () {
    SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 100, 'vat_applicable' => false, 'line_total' => 100]), 'items')
        ->create(['created_by' => $this->user->id]);

    $paid = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 50, 'vat_applicable' => false, 'line_total' => 50]), 'items')
        ->create(['created_by' => $this->user->id]);

    $payout = SupplierPayout::create([
        'supplier_id' => $paid->supplier_id,
        'amount' => 50,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $paid->id,
        'allocated_amount' => 50,
        'deduction_amount' => 0,
    ]);

    Livewire::test('pages::supplier-invoices.index')
        ->assertSee('Unpaid')
        ->assertSee('Paid');
});

test('index can sort by paid_status', function () {
    $paid = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 50, 'vat_applicable' => false, 'line_total' => 50]), 'items')
        ->create(['created_by' => $this->user->id]);

    $payout = SupplierPayout::create([
        'supplier_id' => $paid->supplier_id,
        'amount' => 50,
        'payout_date' => now(),
        'created_by' => $this->user->id,
    ]);
    SupplierPayoutAllocation::create([
        'supplier_payout_id' => $payout->id,
        'supplier_invoice_id' => $paid->id,
        'allocated_amount' => 50,
        'deduction_amount' => 0,
    ]);

    $unpaid = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->state(['quantity' => 1, 'unit_amount' => 100, 'vat_applicable' => false, 'line_total' => 100]), 'items')
        ->create(['created_by' => $this->user->id]);

    Livewire::test('pages::supplier-invoices.index')
        ->call('sortBy', 'paid_status')
        ->assertSeeInOrder([$unpaid->supplier_invoice_no, $paid->supplier_invoice_no])
        ->call('sortBy', 'paid_status')
        ->assertSeeInOrder([$paid->supplier_invoice_no, $unpaid->supplier_invoice_no]);
});

// ── Status enum ───────────────────────────────────────────────
test('status defaults to posted', function () {
    $invoice = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);

    expect($invoice->status)->toBe(SupplierInvoiceStatus::Posted);
});

test('draft state sets status to draft', function () {
    $invoice = SupplierInvoice::factory()->draft()->create(['created_by' => $this->user->id]);

    expect($invoice->status)->toBe(SupplierInvoiceStatus::Draft);
});

// ── Attachments ──────────────────────────────────────────────
test('attachments column stored and cast as array', function () {
    Storage::fake('public');
    $file = UploadedFile::fake()->create('invoice.pdf', 100, 'application/pdf');
    $path = $file->store('supplier-invoice-attachments', 'public');

    $invoice = SupplierInvoice::factory()->create([
        'created_by' => $this->user->id,
        'attachments' => [['path' => $path, 'original_name' => 'invoice.pdf', 'size' => 102400]],
    ]);

    expect($invoice->attachments)->toBeArray()
        ->and($invoice->attachments[0]['original_name'])->toBe('invoice.pdf');
});

// ── Relations ────────────────────────────────────────────────
test('supplier relation resolves via withTrashed', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $this->user->id,
    ]);
    $supplier->delete();

    $invoice->refresh();
    expect($invoice->supplier)->not->toBeNull()
        ->and($invoice->supplier->id)->toBe($supplier->id);
});

test('items cascade delete when invoice is deleted', function () {
    $invoice = SupplierInvoice::factory()
        ->has(SupplierInvoiceItem::factory()->count(3), 'items')
        ->create(['created_by' => $this->user->id]);

    $ids = $invoice->items->pluck('id');
    $invoice->delete();

    foreach ($ids as $id) {
        expect(SupplierInvoiceItem::find($id))->toBeNull();
    }
});

// ── Index page ────────────────────────────────────────────────
test('index page loads', function () {
    SupplierInvoice::factory()->count(3)->create(['created_by' => $this->user->id]);

    $this->get(route('supplier-invoices.index'))
        ->assertOk();
});

// ── Show page ─────────────────────────────────────────────────
test('show page loads for an invoice', function () {
    $invoice = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);

    $this->get(route('supplier-invoices.show', $invoice))
        ->assertOk();
});

// ── Create / Edit routes ──────────────────────────────────────
test('create form page loads', function () {
    $this->get(route('supplier-invoices.create'))
        ->assertOk();
});

test('edit form page loads', function () {
    $invoice = SupplierInvoice::factory()->create(['created_by' => $this->user->id]);

    $this->get(route('supplier-invoices.edit', $invoice))
        ->assertOk();
});
