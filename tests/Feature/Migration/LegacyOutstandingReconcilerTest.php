<?php

use App\Models\CreditAllocation;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\WriteOff;
use App\Services\Migration\LegacyOutstandingReconciler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['AccountEntries', 'Documents']);
    $this->userId = User::factory()->create()->id;
});

function linkLegacyInvoice(int $legacyUid, string $ref, float $osvalue, float $totalValue, array $docOverrides = []): Document
{
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => $legacyUid, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
        'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
        'ref' => $ref, 'bline' => 0,
    ]);

    DB::connection('legacy')->table('AccountEntries')->insert([
        'uid' => $legacyUid, 'rtype' => 'a', 'custid' => 1, 'value' => $totalValue,
        'osvalue' => $osvalue, 'txndate' => '2024-01-01', 'invno' => $ref, 'posttype' => 85,
    ]);

    return Document::factory()->invoice()->create(array_merge([
        'legacy_uid' => $legacyUid,
        'total_value' => $totalValue,
        'doc_date' => '2024-03-15',
    ], $docOverrides));
}

test('osvalue 0 with nothing allocated flags the invoice, no synthetic payment', function () {
    $invoice = linkLegacyInvoice(500, 'INV-1', 0.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect(Payment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0);

    $invoice->refresh();
    expect($invoice->legacy_confirmed_paid)->toBeTrue()
        ->and($invoice->legacy_confirmed_paid_batch)->toBe('B1');
});

test('osvalue above 0 with nothing allocated still just flags the invoice', function () {
    $invoice = linkLegacyInvoice(501, 'INV-2', 30.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect(Payment::count())->toBe(0);
    expect($invoice->fresh()->legacy_confirmed_paid)->toBeTrue();
});

test('a partly allocated invoice with a legacy-confirmed remainder is flagged, no write-off', function () {
    $invoice = linkLegacyInvoice(502, 'INV-3', 20.0, 100.0);

    $existingPayment = Payment::factory()->create();
    PaymentAllocation::create([
        'payment_id' => $existingPayment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 40,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect(WriteOff::count())->toBe(0)
        ->and(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0);

    expect($invoice->fresh()->legacy_confirmed_paid)->toBeTrue();
});

test('a credited amount is subtracted before deciding whether to flag', function () {
    $invoice = linkLegacyInvoice(503, 'INV-4', 0.0, 100.0);

    CreditAllocation::create([
        'invoice_id' => $invoice->id,
        'amount' => 30,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect(Payment::count())->toBe(0);
    expect($invoice->fresh()->legacy_confirmed_paid)->toBeTrue();
});

test('an over-applied invoice has its migrated allocation shrunk and the payment exhausted', function () {
    $invoice = linkLegacyInvoice(504, 'INV-5', 25.0, 100.0);

    $payment = Payment::factory()->create(['legacy_uid' => 9001, 'amount' => 100]);
    $allocation = PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 100,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect((float) $allocation->fresh()->allocated_amount)->toBe(75.0)
        ->and((float) $payment->fresh()->amount)->toBe(100.0)
        ->and($payment->fresh()->is_exhausted)->toBeTrue()
        ->and($invoice->fresh()->legacy_confirmed_paid)->toBeFalse();
});

test('an over-applied allocation reduced to zero is soft-deleted', function () {
    $invoice = linkLegacyInvoice(505, 'INV-6', 100.0, 100.0);

    $payment = Payment::factory()->create(['legacy_uid' => 9002, 'amount' => 100]);
    $allocation = PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 100,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect(PaymentAllocation::withTrashed()->find($allocation->id)->trashed())->toBeTrue()
        ->and(PaymentAllocation::find($allocation->id))->toBeNull()
        ->and($payment->fresh()->is_exhausted)->toBeTrue()
        ->and((float) $payment->fresh()->amount)->toBe(100.0);
});

test('an invoice whose balance already matches legacy is left untouched', function () {
    $invoice = linkLegacyInvoice(506, 'INV-7', 0.0, 100.0);

    $payment = Payment::factory()->create();
    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 100,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $plan = $reconciler->plan('B1');

    expect($reconciler->isEmpty($plan))->toBeTrue();

    $reconciler->apply($plan);

    expect($invoice->fresh()->legacy_confirmed_paid)->toBeFalse();
});

test('the excluded customer is skipped while a normal customer is still flagged', function () {
    $morrInvoice = linkLegacyInvoice(507, 'INV-8A', 0.0, 100.0);
    $normalInvoice = linkLegacyInvoice(508, 'INV-8B', 0.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, $morrInvoice->customer_id);
    $plan = $reconciler->plan('B1');

    expect($plan['flagged_from_row_count'])->toBe(1);

    $reconciler->apply($plan);

    expect($morrInvoice->fresh()->legacy_confirmed_paid)->toBeFalse()
        ->and($normalInvoice->fresh()->legacy_confirmed_paid)->toBeTrue();
});

test('an invoice with no AccountEntries row and no local evidence is left untouched', function () {
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 600, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
        'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
        'ref' => 'INV-X', 'bline' => 0,
    ]);

    $invoice = Document::factory()->invoice()->create([
        'legacy_uid' => 600, 'total_value' => 100, 'doc_date' => '2024-03-15',
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $plan = $reconciler->plan('B1');

    expect($reconciler->isEmpty($plan))->toBeTrue();

    $reconciler->apply($plan);

    expect($invoice->fresh()->legacy_confirmed_paid)->toBeFalse();
});

test('an invoice with no AccountEntries row but full local evidence is flagged', function () {
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 601, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
        'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
        'ref' => 'INV-Y', 'bline' => 0,
    ]);

    $invoice = Document::factory()->invoice()->create([
        'legacy_uid' => 601, 'total_value' => 100, 'doc_date' => '2024-03-15',
    ]);

    CreditAllocation::create([
        'invoice_id' => $invoice->id,
        'amount' => 100,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $plan = $reconciler->plan('B1');

    expect($plan['flagged_from_no_row_count'])->toBe(1);

    $reconciler->apply($plan);

    expect($invoice->fresh()->legacy_confirmed_paid)->toBeTrue()
        ->and($invoice->fresh()->legacy_confirmed_paid_batch)->toBe('B1');
});

test('an ambiguous ref is counted but not settled', function () {
    linkLegacyInvoice(700, 'DUP', 0.0, 100.0);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 701, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
        'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
        'ref' => 'DUP', 'bline' => 0,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $plan = $reconciler->plan('B1');

    expect($plan['ambiguous_ref_count'])->toBe(1)
        ->and($plan['flagged_from_row_count'])->toBe(0);
});

test('an over-applied invoice with no migrated allocation to shrink is unreducible', function () {
    linkLegacyInvoice(509, 'INV-11', 150.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $plan = $reconciler->plan('B1');

    expect($plan['unreducible']['count'])->toBe(1);

    $reconciler->apply($plan);

    expect(Payment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0)
        ->and(WriteOff::count())->toBe(0);
});

test('apply is idempotent across batches', function () {
    $invoice = linkLegacyInvoice(510, 'INV-12', 0.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect($invoice->fresh()->legacy_confirmed_paid_batch)->toBe('B1');

    $plan2 = $reconciler->plan('B2');
    expect($reconciler->isEmpty($plan2))->toBeTrue();

    $reconciler->apply($plan2);

    expect($invoice->fresh()->legacy_confirmed_paid_batch)->toBe('B1');
});

test('revert clears only this batch\'s flags without touching pre-existing allocations', function () {
    $flaggedInvoice = linkLegacyInvoice(511, 'INV-13A', 0.0, 100.0);
    $partiallyAllocatedInvoice = linkLegacyInvoice(512, 'INV-13B', 20.0, 100.0);

    $existingPayment = Payment::factory()->create();
    PaymentAllocation::create([
        'payment_id' => $existingPayment->id,
        'document_id' => $partiallyAllocatedInvoice->id,
        'allocated_amount' => 40,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect($flaggedInvoice->fresh()->legacy_confirmed_paid)->toBeTrue()
        ->and($partiallyAllocatedInvoice->fresh()->legacy_confirmed_paid)->toBeTrue();

    $result = $reconciler->revert('B1');

    expect($result['flags_cleared'])->toBe(2)
        ->and($flaggedInvoice->fresh()->legacy_confirmed_paid)->toBeFalse()
        ->and($partiallyAllocatedInvoice->fresh()->legacy_confirmed_paid)->toBeFalse()
        ->and(PaymentAllocation::count())->toBe(1);
});

test('registering a real payment against a flagged invoice lifts the flag', function () {
    $invoice = linkLegacyInvoice(513, 'INV-14', 0.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect($invoice->fresh()->legacy_confirmed_paid)->toBeTrue();

    $payment = Payment::factory()->create();
    PaymentAllocation::create([
        'payment_id' => $payment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 100,
    ]);

    expect($invoice->fresh()->legacy_confirmed_paid)->toBeFalse()
        ->and($invoice->fresh()->legacy_confirmed_paid_batch)->toBeNull();
});
