<?php

use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\WriteOff;
use App\UserRole;

const RECON_CONFIRM = 'Create payments / write-offs for the invoices above?';

beforeEach(function () {
    $this->morr = Customer::factory()->create(['reference' => 'MORR']);
});

function makeAdmin(): User
{
    return User::factory()->create(['role' => UserRole::Admin]);
}

function eligibleInvoice(array $overrides = []): Document
{
    return Document::factory()->invoice()->create(array_merge([
        'doc_date' => '2025-06-15',
        'total_value' => 100,
    ], $overrides));
}

test('path A creates a backdated cash payment and a matching allocation for the residual', function () {
    $admin = makeAdmin();
    $invoice = eligibleInvoice();

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    $payments = Payment::where('customer_id', $invoice->customer_id)->get();
    expect($payments)->toHaveCount(1);

    $payment = $payments->first();
    expect((float) $payment->amount)->toBe(100.0)
        ->and($payment->source_type->value)->toBe('cash')
        ->and($payment->payment_method_id)->toBeNull()
        ->and($payment->reconciliation_batch)->not->toBeNull()
        ->and(str_contains($payment->notes, '[LEGACY-RECON '))->toBeTrue()
        ->and(str_contains($payment->notes, 'Confirmed from legacy system that these invoices are PAID'))->toBeTrue()
        ->and($payment->payment_date->toDateString())->toBe('2025-06-15')
        ->and($payment->created_at->toDateString())->toBe('2025-06-15')
        ->and($payment->updated_at->toDateString())->toBe('2025-06-15');

    $allocations = PaymentAllocation::where('document_id', $invoice->id)->get();
    expect($allocations)->toHaveCount(1);

    $allocation = $allocations->first();
    expect((float) $allocation->allocated_amount)->toBe(100.0)
        ->and($allocation->payment_id)->toBe($payment->id)
        ->and($allocation->created_at->toDateString())->toBe('2025-06-15')
        ->and($allocation->updated_at->toDateString())->toBe('2025-06-15');

    expect($invoice->fresh()->is_settled)->toBeFalse();
});

test('path B writes off the residual for an already partly allocated invoice without a new payment', function () {
    $admin = makeAdmin();
    $invoice = eligibleInvoice(['total_value' => 100]);

    PaymentAllocation::factory()->create([
        'document_id' => $invoice->id,
        'allocated_amount' => 40,
    ]);

    $paymentsBefore = Payment::count();

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::count())->toBe($paymentsBefore)
        ->and(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0);

    $writeOffs = WriteOff::where('document_id', $invoice->id)->get();
    expect($writeOffs)->toHaveCount(1);

    $writeOff = $writeOffs->first();
    expect((float) $writeOff->amount)->toBe(60.0)
        ->and(str_contains($writeOff->reason, '[LEGACY-RECON '))->toBeTrue()
        ->and(str_contains($writeOff->reason, 'Confirmed from legacy system that these invoices are PAID'))->toBeTrue()
        ->and($writeOff->written_off_by)->toBe($admin->id)
        ->and($writeOff->written_off_at->toDateString())->toBe('2025-06-15')
        ->and($writeOff->created_at->toDateString())->toBe('2025-06-15')
        ->and($writeOff->updated_at->toDateString())->toBe('2025-06-15');

    expect($invoice->fresh()->is_settled)->toBeFalse();
});

test('an invoice dated after the default as-of cutoff is left untouched', function () {
    $admin = makeAdmin();
    $invoice = eligibleInvoice(['doc_date' => '2025-09-01']);

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::where('customer_id', $invoice->customer_id)->count())->toBe(0)
        ->and(PaymentAllocation::where('document_id', $invoice->id)->count())->toBe(0)
        ->and(WriteOff::where('document_id', $invoice->id)->count())->toBe(0);
});

test('a fully allocated invoice with no residual is left untouched', function () {
    $admin = makeAdmin();
    $invoice = eligibleInvoice(['total_value' => 100]);

    PaymentAllocation::factory()->create([
        'document_id' => $invoice->id,
        'allocated_amount' => 100,
    ]);

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0)
        ->and(WriteOff::where('document_id', $invoice->id)->count())->toBe(0);
});

test('the excluded customer is skipped while other customers are reconciled', function () {
    $admin = makeAdmin();
    $excludedInvoice = eligibleInvoice(['customer_id' => $this->morr->id]);
    $normalInvoice = eligibleInvoice();

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::where('customer_id', $this->morr->id)->count())->toBe(0)
        ->and(PaymentAllocation::where('document_id', $excludedInvoice->id)->count())->toBe(0)
        ->and(WriteOff::where('document_id', $excludedInvoice->id)->count())->toBe(0);

    expect(Payment::where('customer_id', $normalInvoice->customer_id)->count())->toBe(1)
        ->and(PaymentAllocation::where('document_id', $normalInvoice->id)->count())->toBe(1);
});

test('an --exclude-reference that matches no customer fails the run and writes nothing', function () {
    makeAdmin();
    eligibleInvoice();

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--exclude-reference' => 'DOESNOTEXIST'])
        ->expectsOutputToContain('matches no customer')
        ->assertFailed();

    expect(Payment::count())->toBe(0)
        ->and(WriteOff::count())->toBe(0);
});

test('a path A invoice fully covered by credit allocations is skipped', function () {
    $admin = makeAdmin();
    $invoice = eligibleInvoice(['total_value' => 100]);

    CreditAllocation::create(['invoice_id' => $invoice->id, 'amount' => 100]);

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::where('customer_id', $invoice->customer_id)->count())->toBe(0)
        ->and(PaymentAllocation::where('document_id', $invoice->id)->count())->toBe(0)
        ->and(WriteOff::where('document_id', $invoice->id)->count())->toBe(0);
});

test('a path A invoice partly credited only pays the uncredited residual', function () {
    $admin = makeAdmin();
    $invoice = eligibleInvoice(['total_value' => 100]);

    CreditAllocation::create(['invoice_id' => $invoice->id, 'amount' => 30]);

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    $payment = Payment::where('customer_id', $invoice->customer_id)->sole();
    expect((float) $payment->amount)->toBe(70.0);

    $allocation = PaymentAllocation::where('document_id', $invoice->id)->sole();
    expect((float) $allocation->allocated_amount)->toBe(70.0);
});

test('an invoice with a live write-off is skipped entirely', function () {
    $admin = makeAdmin();
    $invoice = eligibleInvoice(['total_value' => 100]);

    WriteOff::factory()->create([
        'document_id' => $invoice->id,
        'amount' => 100,
        'reason' => 'Pre-existing write-off',
    ]);

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0);

    $writeOffs = WriteOff::where('document_id', $invoice->id)->get();
    expect($writeOffs)->toHaveCount(1)
        ->and($writeOffs->first()->reason)->toBe('Pre-existing write-off');
});

test('--dry-run computes the summary but writes nothing', function () {
    $admin = makeAdmin();
    $pathA = eligibleInvoice(['total_value' => 100]);
    $pathB = eligibleInvoice(['total_value' => 100]);

    PaymentAllocation::factory()->create([
        'document_id' => $pathB->id,
        'allocated_amount' => 40,
    ]);

    $paymentsBefore = Payment::count();
    $allocationsBefore = PaymentAllocation::count();
    $writeOffsBefore = WriteOff::count();

    $this->artisan('payments:reconcile-legacy-paid-invoices', [
        '--user' => $admin->id,
        '--dry-run' => true,
    ])
        ->expectsOutputToContain('Dry run')
        ->assertSuccessful();

    expect(Payment::count())->toBe($paymentsBefore)
        ->and(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe($allocationsBefore)
        ->and(WriteOff::count())->toBe($writeOffsBefore)
        ->and(PaymentAllocation::where('document_id', $pathA->id)->count())->toBe(0)
        ->and(WriteOff::where('document_id', $pathB->id)->count())->toBe(0);
});

test('running the command twice is idempotent', function () {
    $admin = makeAdmin();
    $pathA = eligibleInvoice(['total_value' => 100]);
    $pathB = eligibleInvoice(['total_value' => 100]);

    PaymentAllocation::factory()->create([
        'document_id' => $pathB->id,
        'allocated_amount' => 40,
    ]);

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    $paymentsAfterFirst = Payment::count();
    $allocationsAfterFirst = PaymentAllocation::count();
    $writeOffsAfterFirst = WriteOff::count();

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::count())->toBe($paymentsAfterFirst)
        ->and(PaymentAllocation::count())->toBe($allocationsAfterFirst)
        ->and(WriteOff::count())->toBe($writeOffsAfterFirst);
});

test('--revert soft-deletes a batch and the invoices become candidates again', function () {
    $admin = makeAdmin();
    $pathA = eligibleInvoice(['total_value' => 100]);
    $pathB = eligibleInvoice(['total_value' => 100]);

    PaymentAllocation::factory()->create([
        'document_id' => $pathB->id,
        'allocated_amount' => 40,
    ]);

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    $batch = Payment::whereNotNull('reconciliation_batch')->latest('id')->first()->reconciliation_batch;

    $this->artisan('payments:reconcile-legacy-paid-invoices', [
        '--user' => $admin->id,
        '--revert' => $batch,
    ])->assertSuccessful();

    expect(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0)
        ->and(Payment::onlyTrashed()->whereNotNull('reconciliation_batch')->count())->toBe(1)
        ->and(PaymentAllocation::where('document_id', $pathA->id)->count())->toBe(0)
        ->and(PaymentAllocation::onlyTrashed()->where('document_id', $pathA->id)->count())->toBe(1)
        ->and(WriteOff::where('document_id', $pathB->id)->count())->toBe(0)
        ->and(WriteOff::onlyTrashed()->where('document_id', $pathB->id)->count())->toBe(1);

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::whereNotNull('reconciliation_batch')->count())->toBe(1)
        ->and(PaymentAllocation::where('document_id', $pathA->id)->count())->toBe(1)
        ->and(WriteOff::where('document_id', $pathB->id)->count())->toBe(1);
});

test('a pre-existing trashed allocation does not block a fresh path A allocation', function () {
    $admin = makeAdmin();
    $invoice = eligibleInvoice(['total_value' => 100]);

    $stale = PaymentAllocation::factory()->create([
        'document_id' => $invoice->id,
        'allocated_amount' => 50,
    ]);
    $stale->delete();

    $this->artisan('payments:reconcile-legacy-paid-invoices', ['--user' => $admin->id])
        ->expectsConfirmation(RECON_CONFIRM, 'yes')
        ->assertSuccessful();

    expect(Payment::whereNotNull('reconciliation_batch')->where('customer_id', $invoice->customer_id)->count())->toBe(1)
        ->and(PaymentAllocation::where('document_id', $invoice->id)->count())->toBe(1)
        ->and(PaymentAllocation::withTrashed()->where('document_id', $invoice->id)->count())->toBe(1);

    $allocation = PaymentAllocation::where('document_id', $invoice->id)->sole();
    expect((float) $allocation->allocated_amount)->toBe(100.0);
});

test('the run fails when there is no admin user and no --user is given', function () {
    $invoice = eligibleInvoice();

    expect(User::where('role', UserRole::Admin)->exists())->toBeFalse();

    $this->artisan('payments:reconcile-legacy-paid-invoices')
        ->expectsOutputToContain('No user to attribute rows to')
        ->assertFailed();

    expect(Payment::count())->toBe(0)
        ->and(PaymentAllocation::where('document_id', $invoice->id)->count())->toBe(0)
        ->and(WriteOff::count())->toBe(0);
});
