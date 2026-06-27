<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Setting::updateOrCreate(['key' => 'supdn_prefix'], ['key' => 'supdn_prefix', 'value' => 'SUPDN', 'type' => 'string']);
    Setting::updateOrCreate(['key' => 'number_padding'], ['key' => 'number_padding', 'value' => '4', 'type' => 'integer']);
    Setting::flushCache();
});

test('nextNumber generates SUPDN prefix padded to 4 digits', function () {
    $number = SupplierDebitNote::nextNumber();

    expect($number)->toBe('SUPDN-0001');
});

test('nextNumber increments from existing records', function () {
    $supplier = Supplier::factory()->create();

    SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    $number = SupplierDebitNote::nextNumber();

    expect($number)->toBe('SUPDN-0002');
});

test('creating a debit note sets reference automatically', function () {
    $supplier = Supplier::factory()->create();

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 50,
        'total' => 50,
        'created_by' => $this->user->id,
    ]);

    expect($note->reference)->toBe('SUPDN-0001');
});

test('isApplied returns false before any pivot attachment', function () {
    $supplier = Supplier::factory()->create();

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    expect($note->isApplied())->toBeFalse();
});

test('isApplied returns true after attaching via appliedInvoices', function () {
    $supplier = Supplier::factory()->create();

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    $invoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'created_by' => $this->user->id,
    ]);

    $note->appliedInvoices()->attach($invoice->id, [
        'applied_amount' => 100,
        'applied_at' => now(),
    ]);

    expect($note->isApplied())->toBeTrue();
});

test('totalDeductions sums applied_amount from pivot across multiple invoices', function () {
    $supplier = Supplier::factory()->create();

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 300,
        'total' => 300,
        'created_by' => $this->user->id,
    ]);

    $invoice1 = SupplierInvoice::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $this->user->id]);
    $invoice2 = SupplierInvoice::factory()->create(['supplier_id' => $supplier->id, 'created_by' => $this->user->id]);

    $note->appliedInvoices()->attach($invoice1->id, ['applied_amount' => 120, 'applied_at' => now()]);
    $note->appliedInvoices()->attach($invoice2->id, ['applied_amount' => 80, 'applied_at' => now()]);

    $note->load('appliedInvoices');

    expect($note->totalDeductions())->toBe(200.0);
});

test('soft-deleted debit note is excluded from available debit notes query', function () {
    $supplier = Supplier::factory()->create();

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    $note->delete();

    $results = SupplierDebitNote::where('supplier_id', $supplier->id)
        ->whereDoesntHave('appliedInvoices')
        ->where('status', 'committed')
        ->get();

    expect($results)->toHaveCount(0);
});
