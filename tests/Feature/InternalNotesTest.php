<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoice;
use App\Models\User;
use App\Services\CustomerStatementService;
use App\Services\PaymentAllocator;
use App\Services\SupplierStatementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->actingAs(User::factory()->admin()->create(['email_verified_at' => now()]));
});

function makeDebitNote(Supplier $supplier, bool $internal): SupplierDebitNote
{
    return SupplierDebitNote::create([
        'supplier_id' => $supplier->id,
        'doc_date' => now(),
        'subtotal' => 100,
        'total' => 100,
        'is_internal' => $internal,
        'created_by' => auth()->id(),
    ]);
}

test('available credit ignores internal credit notes', function () {
    $customer = Customer::factory()->create();
    Document::factory()->creditNote()->for($customer)->create(['total_value' => 80, 'is_internal' => false]);
    Document::factory()->creditNote()->for($customer)->create(['total_value' => 500, 'is_internal' => true]);

    expect(Document::availableCreditForCustomer($customer->id))->toBe(80.0);
});

test('customer statement credit note rows exclude internal notes', function () {
    $customer = Customer::factory()->create();
    $external = Document::factory()->creditNote()->for($customer)->create(['is_internal' => false]);
    Document::factory()->creditNote()->for($customer)->create(['is_internal' => true]);

    $rows = app(CustomerStatementService::class)->buildCreditNoteRows($customer, []);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['doc_number'])->toBe($external->doc_number);
});

test('supplier statement debit note rows exclude internal notes', function () {
    $supplier = Supplier::factory()->create();
    $external = makeDebitNote($supplier, false);
    makeDebitNote($supplier, true);

    $rows = app(SupplierStatementService::class)->buildDebitNoteRows($supplier, []);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['doc_number'])->toBe($external->reference);
});

test('funding a payment from an internal credit note is rejected', function () {
    $customer = Customer::factory()->create();
    $internal = Document::factory()->creditNote()->for($customer)->create(['total_value' => 100, 'is_internal' => true]);
    $payment = Payment::factory()->create(['customer_id' => $customer->id]);

    app(PaymentAllocator::class)->fundFromCreditNotes($payment, [$internal->id], 50.0);
})->throws(InvalidArgumentException::class);

test('internal credit note is saved without a credited invoice', function () {
    $customer = Customer::factory()->create(['trade_discount' => 0]);
    $invoice = Document::factory()->invoice()->for($customer)->create();

    Livewire::test('pages::credit-notes.create')
        ->set('customer_id', $customer->id)
        ->set('doc_date', '2026-06-11')
        ->set('credited_invoice_id', $invoice->id)
        ->set('is_internal', true)
        ->set('items', [
            ['id' => null, 'details' => 'Widget A', 'quantity' => '2', 'price' => '50', 'per' => 'each', 'is_note' => false, 'discount_percent' => null],
        ])
        ->call('save')
        ->assertHasNoErrors();

    $note = Document::creditNotes()->first();

    expect($note->is_internal)->toBeTrue()
        ->and($note->credited_invoice_id)->toBeNull();
});

test('internal debit note is committed without an invoice or application', function () {
    $supplier = Supplier::factory()->create();
    $invoice = SupplierInvoice::factory()->create(['supplier_id' => $supplier->id]);

    Livewire::test('pages::supplier-debit-notes.create')
        ->set('supplier_id', $supplier->id)
        ->set('supplier_invoice_id', $invoice->id)
        ->set('is_internal', true)
        ->set('items', [
            ['id' => null, 'details' => 'Damaged goods', 'quantity' => '1', 'price' => '40', 'per' => 'each', 'is_note' => false, 'discount_percent' => null, 'vat_applicable' => true, 'net_override' => null, 'vat_override' => null],
        ])
        ->call('commitDebitNote')
        ->assertHasNoErrors();

    $note = SupplierDebitNote::first();

    expect($note->is_internal)->toBeTrue()
        ->and($note->supplier_invoice_id)->toBeNull()
        ->and(DB::table('supplier_invoice_debit_notes')->count())->toBe(0);
});

test('internal notes render without customer or supplier facing actions', function () {
    $creditNote = Document::factory()->creditNote()->create(['is_internal' => true]);
    $debitNote = makeDebitNote(Supplier::factory()->create(), true);

    $this->get(route('credit-notes.show', $creditNote))
        ->assertOk()
        ->assertSee('Internal')
        ->assertDontSee('Send Email')
        ->assertDontSee('Download PDF');

    $this->get(route('supplier-debit-notes.show', $debitNote))
        ->assertOk()
        ->assertSee('Internal')
        ->assertDontSee('Send Email')
        ->assertDontSee('Download PDF');
});
