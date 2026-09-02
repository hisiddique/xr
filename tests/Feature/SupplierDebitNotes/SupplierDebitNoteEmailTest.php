<?php

use App\Mail\SupplierDebitNoteMail;
use App\Models\Setting;
use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteEmailLog;
use App\Models\SupplierDebitNoteItem;
use App\Models\User;
use App\Services\SupplierDebitNoteEmailService;
use App\SupplierDebitNoteStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    Setting::flushCache();
    Setting::set('company_name', 'Test Co', 'string');
    Setting::set('vat_rate', '20', 'float');
    Setting::set('supdn_prefix', 'SUPDN', 'string');
    Setting::set('number_padding', '4', 'integer');
    Setting::flushCache();
});

if (! function_exists('makeSupplierDebitNoteForEmail')) {
    function makeSupplierDebitNoteForEmail(User $user): SupplierDebitNote
    {
        $supplier = Supplier::factory()->create(['email' => 'supplier@example.com']);

        $note = SupplierDebitNote::create([
            'supplier_id' => $supplier->id,
            'doc_date' => now(),
            'supplier_invoice_id' => null,
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

it('sends SupplierDebitNoteMail to the recipient', function () {
    Mail::fake();

    $note = makeSupplierDebitNoteForEmail($this->user);

    app(SupplierDebitNoteEmailService::class)->send($note, ['supplier@example.com']);

    Mail::assertSent(SupplierDebitNoteMail::class, function (SupplierDebitNoteMail $mail) use ($note) {
        return $mail->debitNote->is($note)
            && $mail->hasTo('supplier@example.com');
    });
});

it('creates a log row with sent status', function () {
    Mail::fake();

    $note = makeSupplierDebitNoteForEmail($this->user);

    $log = app(SupplierDebitNoteEmailService::class)->send($note, ['supplier@example.com']);

    expect($log)->toBeInstanceOf(SupplierDebitNoteEmailLog::class)
        ->and($log->status)->toBe('sent')
        ->and($log->recipient_email)->toBe('supplier@example.com')
        ->and($log->supplier_debit_note_id)->toBe($note->id);

    $this->assertDatabaseHas('supplier_debit_note_email_logs', [
        'supplier_debit_note_id' => $note->id,
        'recipient_email' => 'supplier@example.com',
        'status' => 'sent',
    ]);
});

it('creates a failed log and re-throws when mail throws', function () {
    Mail::shouldReceive('to->send')->andThrow(new RuntimeException('SMTP down'));

    $note = makeSupplierDebitNoteForEmail($this->user);

    expect(fn () => app(SupplierDebitNoteEmailService::class)->send($note, ['supplier@example.com']))
        ->toThrow(RuntimeException::class, 'SMTP down');

    $log = SupplierDebitNoteEmailLog::where('supplier_debit_note_id', $note->id)
        ->where('status', 'failed')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->error_message)->toContain('SMTP down');
});

it('validates that at least one recipient is present', function () {
    $note = makeSupplierDebitNoteForEmail($this->user);

    Livewire::test('pages::supplier-debit-notes.email-modal', ['debitNote' => $note])
        ->set('emails', [])
        ->call('send')
        ->assertHasErrors('emails');
});

it('sends the debit note from the email modal', function () {
    Mail::fake();

    $note = makeSupplierDebitNoteForEmail($this->user);

    Livewire::test('pages::supplier-debit-notes.email-modal', ['debitNote' => $note])
        ->set('emails', ['supplier@example.com'])
        ->set('notes', 'hi')
        ->call('send')
        ->assertHasNoErrors();

    Mail::assertSent(SupplierDebitNoteMail::class);

    expect(
        SupplierDebitNoteEmailLog::where('supplier_debit_note_id', $note->id)
            ->where('status', 'sent')
            ->exists()
    )->toBeTrue();
});
