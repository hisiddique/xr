<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteItem;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();

    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => '20', 'type' => 'integer']);
    Setting::flushCache();
});

test('backfill applies VAT only to notes whose supplier has vat_applied enabled', function () {
    $vatSupplier = Supplier::factory()->create(['vat_applied' => true]);
    $noVatSupplier = Supplier::factory()->create(['vat_applied' => false]);

    $vatNote = SupplierDebitNote::create([
        'supplier_id' => $vatSupplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    $noVatNote = SupplierDebitNote::create([
        'supplier_id' => $noVatSupplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 50,
        'total' => 50,
        'created_by' => $this->user->id,
    ]);

    $this->artisan('supplier-debit-notes:backfill-vat')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    $vatNote->refresh();
    $noVatNote->refresh();

    expect((float) $vatNote->vat_amount)->toBe(20.0);
    expect((float) $vatNote->total)->toBe(120.0);

    expect((float) $noVatNote->vat_amount)->toBe(0.0);
    expect((float) $noVatNote->total)->toBe(50.0);
});

test('backfill skips notes that already have a vat_amount', function () {
    $supplier = Supplier::factory()->create(['vat_applied' => true]);

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'vat_amount' => 15,
        'total' => 115,
        'created_by' => $this->user->id,
    ]);

    $this->artisan('supplier-debit-notes:backfill-vat')
        ->assertExitCode(0);

    $note->refresh();

    expect((float) $note->vat_amount)->toBe(15.0);
    expect((float) $note->total)->toBe(115.0);
});

test('backfill keeps applied-invoice pivot amount in sync with the recalculated total', function () {
    $supplier = Supplier::factory()->create(['vat_applied' => true]);

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

    $this->artisan('supplier-debit-notes:backfill-vat')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    $pivotAmount = (float) $note->appliedInvoices()->first()->pivot->applied_amount;

    expect($pivotAmount)->toBe(120.0);
});

test('backfill syncs item vat_applicable to false for a note with no VAT', function () {
    $supplier = Supplier::factory()->create(['vat_applied' => false]);

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 50,
        'vat_amount' => 0,
        'total' => 50,
        'created_by' => $this->user->id,
    ]);

    $item = SupplierDebitNoteItem::create([
        'supplier_debit_note_id' => $note->id,
        'description' => 'Damaged goods',
        'quantity' => 1,
        'amount' => 50,
        'vat_applicable' => true,
        'total' => 50,
        'sort_order' => 0,
    ]);

    $this->artisan('supplier-debit-notes:backfill-vat')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect($item->fresh()->vat_applicable)->toBeFalse();
});

test('backfill syncs item vat_applicable to true once a note is recalculated with VAT', function () {
    $supplier = Supplier::factory()->create(['vat_applied' => true]);

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'vat_amount' => 0,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    $item = SupplierDebitNoteItem::create([
        'supplier_debit_note_id' => $note->id,
        'description' => 'Damaged goods',
        'quantity' => 1,
        'amount' => 100,
        'vat_applicable' => false,
        'total' => 100,
        'sort_order' => 0,
    ]);

    $this->artisan('supplier-debit-notes:backfill-vat')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertExitCode(0);

    expect($item->fresh()->vat_applicable)->toBeTrue();
});

test('dry-run does not touch item vat_applicable', function () {
    $supplier = Supplier::factory()->create(['vat_applied' => false]);

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 50,
        'vat_amount' => 0,
        'total' => 50,
        'created_by' => $this->user->id,
    ]);

    $item = SupplierDebitNoteItem::create([
        'supplier_debit_note_id' => $note->id,
        'description' => 'Damaged goods',
        'quantity' => 1,
        'amount' => 50,
        'vat_applicable' => true,
        'total' => 50,
        'sort_order' => 0,
    ]);

    $this->artisan('supplier-debit-notes:backfill-vat', ['--dry-run' => true])
        ->assertExitCode(0);

    expect($item->fresh()->vat_applicable)->toBeTrue();
});

test('dry-run makes no changes', function () {
    $supplier = Supplier::factory()->create(['vat_applied' => true]);

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    $this->artisan('supplier-debit-notes:backfill-vat', ['--dry-run' => true])
        ->assertExitCode(0);

    $note->refresh();

    expect((float) $note->vat_amount)->toBe(0.0);
    expect((float) $note->total)->toBe(100.0);
});
