<?php

use App\Models\CreditAllocation;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Models\WriteOff;
use App\PaymentSourceType;
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

test('osvalue 0 with nothing allocated settles the full balance via a synthetic cash payment', function () {
    $invoice = linkLegacyInvoice(500, 'INV-1', 0.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    $payments = Payment::where('customer_id', $invoice->customer_id)->get();
    expect($payments)->toHaveCount(1);

    $payment = $payments->first();
    expect((float) $payment->amount)->toBe(100.0)
        ->and($payment->source_type)->toBe(PaymentSourceType::Cash)
        ->and($payment->payment_method_id)->toBeNull()
        ->and($payment->legacy_uid)->toBeNull()
        ->and($payment->reconciliation_batch)->toBe('B1')
        ->and($payment->payment_date->toDateString())->toBe($invoice->doc_date->toDateString())
        ->and($payment->created_at->toDateString())->toBe($invoice->doc_date->toDateString())
        ->and($payment->updated_at->toDateString())->toBe($invoice->doc_date->toDateString());

    $allocations = PaymentAllocation::where('document_id', $invoice->id)->get();
    expect($allocations)->toHaveCount(1)
        ->and((float) $allocations->first()->allocated_amount)->toBe(100.0);
});

test('osvalue above 0 with nothing allocated settles only the delta', function () {
    $invoice = linkLegacyInvoice(501, 'INV-2', 30.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    $payment = Payment::where('customer_id', $invoice->customer_id)->sole();
    expect((float) $payment->amount)->toBe(70.0);

    $allocation = PaymentAllocation::where('document_id', $invoice->id)->sole();
    expect((float) $allocation->allocated_amount)->toBe(70.0);
});

test('a partly allocated invoice is written off for the delta with no new payment', function () {
    $invoice = linkLegacyInvoice(502, 'INV-3', 20.0, 100.0);

    $existingPayment = Payment::factory()->create();
    PaymentAllocation::create([
        'payment_id' => $existingPayment->id,
        'document_id' => $invoice->id,
        'allocated_amount' => 40,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    expect(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0);

    $writeOffs = WriteOff::where('document_id', $invoice->id)->get();
    expect($writeOffs)->toHaveCount(1);

    $writeOff = $writeOffs->first();
    expect((float) $writeOff->amount)->toBe(40.0)
        ->and($writeOff->reason)->toContain('[LEGACY-RECON B')
        ->and($writeOff->written_off_by)->toBe($this->userId)
        ->and($writeOff->written_off_at->toDateString())->toBe($invoice->doc_date->toDateString())
        ->and($writeOff->created_at->toDateString())->toBe($invoice->doc_date->toDateString());
});

test('a credited amount is subtracted before the residual is paid', function () {
    $invoice = linkLegacyInvoice(503, 'INV-4', 0.0, 100.0);

    CreditAllocation::create([
        'invoice_id' => $invoice->id,
        'amount' => 30,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    $payment = Payment::where('customer_id', $invoice->customer_id)->sole();
    expect((float) $payment->amount)->toBe(70.0);
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
        ->and($payment->fresh()->is_exhausted)->toBeTrue();
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

    expect(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0)
        ->and(WriteOff::count())->toBe(0);
});

test('the excluded customer is skipped while a normal customer is still settled', function () {
    $morrInvoice = linkLegacyInvoice(507, 'INV-8A', 0.0, 100.0);
    $normalInvoice = linkLegacyInvoice(508, 'INV-8B', 0.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, $morrInvoice->customer_id);
    $plan = $reconciler->plan('B1');

    expect($plan['path_a_count'])->toBe(1);

    $reconciler->apply($plan);

    expect(Payment::where('customer_id', $morrInvoice->customer_id)->count())->toBe(0)
        ->and(Payment::where('customer_id', $normalInvoice->customer_id)->whereReconciliationBatch('B1')->count())->toBe(1);
});

test('an invoice with no AccountEntries row never enters the plan', function () {
    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 600, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
        'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
        'ref' => 'INV-X', 'bline' => 0,
    ]);

    Document::factory()->invoice()->create([
        'legacy_uid' => 600, 'total_value' => 100, 'doc_date' => '2024-03-15',
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $plan = $reconciler->plan('B1');

    expect($reconciler->isEmpty($plan))->toBeTrue();

    $reconciler->apply($plan);

    expect(Payment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0)
        ->and(WriteOff::count())->toBe(0);
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
        ->and($plan['path_a_count'])->toBe(0);

    $reconciler->apply($plan);

    expect(Payment::count())->toBe(0)
        ->and(PaymentAllocation::count())->toBe(0)
        ->and(WriteOff::count())->toBe(0);
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
    linkLegacyInvoice(510, 'INV-12', 0.0, 100.0);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    $paymentCount = Payment::count();
    $allocationCount = PaymentAllocation::count();
    $writeOffCount = WriteOff::count();

    $plan2 = $reconciler->plan('B2');
    expect($reconciler->isEmpty($plan2))->toBeTrue();

    $reconciler->apply($plan2);

    expect(Payment::count())->toBe($paymentCount)
        ->and(PaymentAllocation::count())->toBe($allocationCount)
        ->and(WriteOff::count())->toBe($writeOffCount);
});

test('revert undoes a Path A and Path B batch without touching pre-existing allocations', function () {
    linkLegacyInvoice(511, 'INV-13A', 0.0, 100.0);
    $pathBInvoice = linkLegacyInvoice(512, 'INV-13B', 20.0, 100.0);

    $existingPayment = Payment::factory()->create();
    PaymentAllocation::create([
        'payment_id' => $existingPayment->id,
        'document_id' => $pathBInvoice->id,
        'allocated_amount' => 40,
    ]);

    $reconciler = new LegacyOutstandingReconciler($this->userId, null);
    $reconciler->apply($reconciler->plan('B1'));

    $result = $reconciler->revert('B1');

    expect($result['payments'])->toBeGreaterThan(0)
        ->and($result['allocations'])->toBeGreaterThan(0)
        ->and($result['write_offs'])->toBeGreaterThan(0)
        ->and(Payment::whereReconciliationBatch('B1')->count())->toBe(0)
        ->and(WriteOff::count())->toBe(0)
        ->and(WriteOff::withTrashed()->count())->toBe(1)
        ->and(PaymentAllocation::count())->toBe(1);
});
