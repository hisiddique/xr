<?php

use App\Models\ExpenseCategory;
use App\Models\Overhead;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\SupplierCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

test('read-only category field reflects selected supplier', function () {
    $supplier = Supplier::factory()->create(['category' => 'overhead_expenses']);

    $component = Livewire::test('pages::supplier-invoices.form')->set('supplier_id', $supplier->id);

    expect($component->get('supplierCategory'))->toBe(SupplierCategory::OverheadExpenses);
});

test('add overhead expense checkbox only visible for overhead-expenses category suppliers', function () {
    $overheadSupplier = Supplier::factory()->create(['category' => 'overhead_expenses']);
    $tradingSupplier = Supplier::factory()->create(['category' => 'trading']);

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $overheadSupplier->id)
        ->assertSee('Add Overhead Expense');

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $tradingSupplier->id)
        ->assertDontSee('Add Overhead Expense');
});

test('saving with add overhead expense checked creates a linked overhead record', function () {
    $expenseCategory = ExpenseCategory::factory()->create();
    $supplier = Supplier::factory()->create(['category' => 'overhead_expenses']);

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('addOverheadExpense', true)
        ->set('overhead_category_id', $expenseCategory->id)
        ->set('overhead_payment_method', 'Bank Transfer')
        ->set('overhead_has_vat', true)
        ->set('items', [['product_code' => '', 'quantity' => 1, 'unit_amount' => 100, 'vat_applicable' => false]])
        ->call('save');

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->sole();

    expect($invoice->overhead_id)->not->toBeNull()
        ->and((float) $invoice->overhead->amount)->toBe(100.0)
        ->and($invoice->overhead->expense_date->format('Y-m-d'))->toBe('2026-01-15')
        ->and($invoice->overhead->category_id)->toBe($expenseCategory->id)
        ->and($invoice->overhead->payment_method)->toBe('Bank Transfer')
        ->and($invoice->overhead->has_vat)->toBeTrue();
});

test('unchecking add overhead expense on an existing invoice deletes the linked overhead', function () {
    $supplier = Supplier::factory()->create(['category' => 'overhead_expenses']);
    $invoice = SupplierInvoice::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $this->user->id]);
    $overhead = Overhead::factory()->create();
    $invoice->update(['overhead_id' => $overhead->id]);

    $component = Livewire::test('pages::supplier-invoices.form', ['supplierInvoice' => $invoice]);

    expect($component->get('addOverheadExpense'))->toBeTrue();

    $component->set('addOverheadExpense', false)->call('save');

    expect($invoice->fresh()->overhead_id)->toBeNull()
        ->and(Overhead::withTrashed()->find($overhead->id)->trashed())->toBeTrue();
});

test('editing an invoice hydrates existing overhead fields into the form', function () {
    $supplier = Supplier::factory()->create(['category' => 'overhead_expenses']);
    $expenseCategory = ExpenseCategory::factory()->create();
    $invoice = SupplierInvoice::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $this->user->id]);
    $overhead = Overhead::factory()->create([
        'category_id' => $expenseCategory->id,
        'payment_method' => 'Cheque',
        'has_vat' => true,
    ]);
    $invoice->update(['overhead_id' => $overhead->id]);

    $component = Livewire::test('pages::supplier-invoices.form', ['supplierInvoice' => $invoice]);

    expect($component->get('addOverheadExpense'))->toBeTrue()
        ->and($component->get('overhead_category_id'))->toBe($expenseCategory->id)
        ->and($component->get('overhead_payment_method'))->toBe('Cheque')
        ->and($component->get('overhead_has_vat'))->toBeTrue();
});

test('zero gross total invoice does not create an overhead even when checkbox is checked', function () {
    $expenseCategory = ExpenseCategory::factory()->create();
    $supplier = Supplier::factory()->create(['category' => 'overhead_expenses']);

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('addOverheadExpense', true)
        ->set('overhead_category_id', $expenseCategory->id)
        ->set('overhead_payment_method', 'Bank Transfer')
        ->set('items', [['product_code' => '', 'quantity' => 1, 'unit_amount' => 0, 'vat_applicable' => false]])
        ->call('save');

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->sole();

    expect($invoice->overhead_id)->toBeNull()
        ->and(Overhead::count())->toBe(0);
});

test('invoice_no is saved per line item and displayed separately from the auto-generated supplier_invoice_no', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('items', [['product_code' => '', 'invoice_no' => 'SUP-REF-999', 'quantity' => 1, 'unit_amount' => 50, 'vat_applicable' => false]])
        ->call('save');

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->sole();

    expect($invoice->items->first()->invoice_no)->toBe('SUP-REF-999')
        ->and($invoice->supplier_invoice_no)->toBe('SUPINV-0001');
});

test('product_code is forced null and quantity defaults to 1 on save since there is no UI input', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-invoices.form')
        ->set('supplier_id', $supplier->id)
        ->set('invoice_date', '2026-01-15')
        ->set('items', [['product_code' => 'IGNORED-CODE', 'invoice_no' => 'INV-1', 'quantity' => 1, 'unit_amount' => 50, 'vat_applicable' => false]])
        ->call('save');

    $invoice = SupplierInvoice::where('supplier_id', $supplier->id)->sole();
    $item = $invoice->items->first();

    expect($item->product_code)->toBeNull()
        ->and((float) $item->quantity)->toBe(1.0);
});
