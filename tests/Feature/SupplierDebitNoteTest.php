<?php

use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

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

test('nextNumber does not reuse a reference belonging to a soft-deleted debit note', function () {
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

    $number = SupplierDebitNote::nextNumber();

    expect($number)->toBe('SUPDN-0002');
});

test('deleting a debit note from the index page does not error', function () {
    $supplier = Supplier::factory()->create();

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::supplier-debit-notes.index')
        ->call('delete', $note->id)
        ->assertHasNoErrors();

    expect(SupplierDebitNote::find($note->id))->toBeNull();
});

test('index search matches the linked supplier invoice ref no', function () {
    $supplier = Supplier::factory()->create();

    $matchingInvoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'supplier_ref_invoice_no' => 'REF-XYZ-999',
    ]);
    $otherInvoice = SupplierInvoice::factory()->create([
        'supplier_id' => $supplier->id,
        'supplier_ref_invoice_no' => 'REF-ABC-111',
    ]);

    $matchingNote = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'supplier_invoice_id' => $matchingInvoice->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);
    $otherNote = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'supplier_invoice_id' => $otherInvoice->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::supplier-debit-notes.index')
        ->set('search', 'REF-XYZ-999')
        ->assertSee($matchingNote->reference)
        ->assertDontSee($otherNote->reference);
});

test('deleting a debit note from the show page does not error', function () {
    $supplier = Supplier::factory()->create();

    $note = SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'status' => SupplierDebitNoteStatus::Committed,
        'subtotal' => 100,
        'total' => 100,
        'created_by' => $this->user->id,
    ]);

    Livewire::test('pages::supplier-debit-notes.show', ['debitNote' => $note])
        ->call('delete')
        ->assertHasNoErrors()
        ->assertRedirect(route('supplier-debit-notes.index'));

    expect(SupplierDebitNote::find($note->id))->toBeNull();
});

function debitNoteRow(array $overrides = []): array
{
    return array_merge([
        'id' => null,
        'details' => 'Damaged goods',
        'quantity' => '1',
        'price' => '100',
        'per' => 'Each',
        'is_note' => false,
        'discount_percent' => null,
        'vat_applicable' => false,
    ], $overrides);
}

test('commitDebitNote ignores trailing empty rows and only stores filled items', function () {
    $supplier = Supplier::factory()->create(['vat_applied' => false]);

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            debitNoteRow(['details' => 'Damaged goods', 'quantity' => '2', 'price' => '10']),
            debitNoteRow(['details' => '', 'quantity' => '', 'price' => '', 'per' => '']),
        ])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::firstWhere('supplier_id', $supplier->id);

    expect($note)->not->toBeNull();
    expect($note->items)->toHaveCount(1);
    expect((float) $note->subtotal)->toBe(20.0);
    expect((float) $note->total)->toBe(20.0);
});

test('commitDebitNote adds VAT for items marked vat_applicable', function () {
    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => '20', 'type' => 'integer']);
    Setting::flushCache();

    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            debitNoteRow(['quantity' => '1', 'price' => '100', 'vat_applicable' => true]),
        ])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::firstWhere('supplier_id', $supplier->id);

    expect((float) $note->subtotal)->toBe(100.0);
    expect((float) $note->vat_amount)->toBe(20.0);
    expect((float) $note->total)->toBe(120.0);
    expect($note->items->first()->vat_applicable)->toBeTrue();
});

test('commitDebitNote does not add VAT for items not marked vat_applicable', function () {
    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            debitNoteRow(['quantity' => '1', 'price' => '100', 'vat_applicable' => false]),
        ])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::firstWhere('supplier_id', $supplier->id);

    expect((float) $note->vat_amount)->toBe(0.0);
    expect((float) $note->total)->toBe(100.0);
    expect($note->items->first()->vat_applicable)->toBeFalse();
});

test('commitDebitNote mixes VAT and non-VAT items on the same note', function () {
    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => '20', 'type' => 'integer']);
    Setting::flushCache();

    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            debitNoteRow(['details' => 'VAT item', 'quantity' => '1', 'price' => '100', 'vat_applicable' => true]),
            debitNoteRow(['details' => 'Non-VAT item', 'quantity' => '1', 'price' => '50', 'vat_applicable' => false]),
        ])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::firstWhere('supplier_id', $supplier->id);

    expect((float) $note->subtotal)->toBe(150.0);
    expect((float) $note->vat_amount)->toBe(20.0);
    expect((float) $note->total)->toBe(170.0);
});

test('commitDebitNote applies per divisor, lot pricing and per-line discount', function () {
    Setting::updateOrCreate(['key' => 'vat_rate'], ['key' => 'vat_rate', 'value' => '20', 'type' => 'integer']);
    Setting::flushCache();

    $supplier = Supplier::factory()->create();

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('doc_date', now()->format('Y-m-d'))
        ->set('items', [
            // per divisor: (10 / 5) * 100 = 200
            debitNoteRow(['details' => 'Pack line', 'quantity' => '10', 'price' => '100', 'per' => '5', 'vat_applicable' => true]),
            // lot: price only, qty ignored -> 250
            debitNoteRow(['details' => 'Lot line', 'quantity' => '3', 'price' => '250', 'per' => 'lot', 'vat_applicable' => false]),
            // discount: 100 * 1 * 25% = 25
            debitNoteRow(['details' => 'Discounted', 'quantity' => '1', 'price' => '100', 'per' => 'Each', 'discount_percent' => 25, 'vat_applicable' => false]),
            // note row: no qty/price
            debitNoteRow(['details' => 'Reason: short delivery', 'quantity' => '', 'price' => '', 'per' => '', 'is_note' => true]),
        ])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::firstWhere('supplier_id', $supplier->id);

    expect($note->items)->toHaveCount(4);
    expect((float) $note->subtotal)->toBe(475.0); // 200 + 250 + 25
    expect((float) $note->vat_amount)->toBe(40.0); // 20% of the 200 line only
    expect((float) $note->total)->toBe(515.0);

    $pack = $note->items->firstWhere('description', 'Pack line');
    expect((float) $pack->line_value)->toBe(200.0);
    expect((float) $pack->total)->toBe(200.0);

    $discounted = $note->items->firstWhere('description', 'Discounted');
    expect((float) $discounted->line_value)->toBe(100.0);
    expect((float) $discounted->total)->toBe(25.0);

    $noteRow = $note->items->firstWhere('is_note', true);
    expect((float) $noteRow->total)->toBe(0.0);
    expect((float) $noteRow->quantity)->toBe(0.0);
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
