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

    Setting::flushCache();
    Setting::set('company_name', 'Test Co', 'string');
    Setting::set('company_address', '1 Test Street', 'string');
    Setting::set('company_email', 'info@testco.com', 'string');
    Setting::set('vat_rate', '20', 'float');
    Setting::set('supdn_prefix', 'SUPDN', 'string');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

if (! function_exists('makeSupplierDebitNote')) {
    function makeSupplierDebitNote(User $user, bool $withInvoice = false): SupplierDebitNote
    {
        $supplier = Supplier::factory()->create(['email' => 'supplier@example.com']);

        $invoice = $withInvoice
            ? SupplierInvoice::factory()->create([
                'supplier_id' => $supplier->id,
                'created_by' => $user->id,
            ])
            : null;

        $note = SupplierDebitNote::create([
            'supplier_id' => $supplier->id,
            'doc_date' => now(),
            'supplier_invoice_id' => $invoice?->id,
            'notes' => 'Returned goods',
            'subtotal' => 100,
            'vat_amount' => 20,
            'total' => 120,
            'status' => SupplierDebitNoteStatus::Committed,
            'created_by' => $user->id,
        ]);

        SupplierDebitNoteItem::create([
            'supplier_debit_note_id' => $note->id,
            'description' => 'Faulty unit',
            'quantity' => 1,
            'amount' => 100,
            'total' => 100,
            'vat_applicable' => true,
            'sort_order' => 1,
        ]);

        return $note;
    }
}

it('returns an application/pdf response for the stream route', function () {
    $note = makeSupplierDebitNote($this->user, withInvoice: true);

    $response = $this->actingAs($this->user)->get(route('supplier-debit-notes.pdf', $note));

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
    expect(strlen($response->getContent()))->toBeGreaterThan(0);
});

it('returns Content-Disposition attachment for the download route', function () {
    $note = makeSupplierDebitNote($this->user, withInvoice: true);

    $response = $this->actingAs($this->user)->get(route('supplier-debit-notes.pdf.download', $note));

    $response->assertStatus(200);
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->headers->get('Content-Disposition'))->toContain("{$note->reference}.pdf");
});

it('redirects unauthenticated users away from the pdf route', function () {
    $note = makeSupplierDebitNote($this->user);

    $this->get(route('supplier-debit-notes.pdf', $note))->assertRedirect();
});

it('renders a pdf for a debit note with no linked supplier invoice', function () {
    $note = makeSupplierDebitNote($this->user, withInvoice: false);

    $response = $this->actingAs($this->user)->get(route('supplier-debit-notes.pdf', $note));

    $response->assertStatus(200);
    expect($response->headers->get('Content-Type'))->toContain('application/pdf');
});
