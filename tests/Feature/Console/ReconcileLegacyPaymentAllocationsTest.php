<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents', 'AccountEntries', 'AccountPostTypes', 'AccountBatchItems']);

    DB::connection('legacy')->table('AccountPostTypes')->insert([
        ['uid' => 3, 'rtype' => 'A', 'inout' => 'IN', 'entryvalue' => 1],
        ['uid' => 25, 'rtype' => 'A', 'inout' => 'IN', 'entryvalue' => -1],
    ]);

    $this->customer = Customer::factory()->create(['legacy_uid' => 1]);
    $this->paymentMethod = LookupPaymentMethod::create(['name' => 'Cash']);

    $this->seedInvoiceEntry = function (int $uid, string $ref, float $osvalue): void {
        DB::connection('legacy')->table('Documents')->insert([
            'uid' => $uid, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
            'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
            'ref' => $ref, 'bline' => 0,
        ]);

        DB::connection('legacy')->table('AccountEntries')->insert([
            'uid' => 90000 + $uid, 'rtype' => 'a', 'custid' => 1, 'posttype' => 85, 'invno' => $ref, 'osvalue' => $osvalue,
        ]);
    };

    $this->seedBatchItem = function (int $cshuid, string $ref, float $paymt, ?int $posttype = null): void {
        DB::connection('legacy')->table('AccountBatchItems')->insert([
            'bhead' => 1, 'bline' => 1, 'cshuid' => $cshuid, 'txnabbr' => 'INV-', 'txnref' => $ref, 'paymt' => $paymt, 'posttype' => $posttype,
        ]);
    };
});

test('allocates a payment to the invoices legacy actually recorded it against', function () {
    ($this->seedInvoiceEntry)(101, '1001', 0.00);
    ($this->seedInvoiceEntry)(102, '1002', 50.00);

    $older = Document::factory()->create([
        'legacy_uid' => 101, 'customer_id' => $this->customer->id,
        'type' => 'INV', 'doc_date' => '2024-01-01', 'total_value' => 100,
    ]);
    $newer = Document::factory()->create([
        'legacy_uid' => 102, 'customer_id' => $this->customer->id,
        'type' => 'INV', 'doc_date' => '2024-02-01', 'total_value' => 100,
    ]);

    $payment = Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'legacy_uid' => 5001, 'customer_id' => $this->customer->id,
        'amount' => 150, 'payment_date' => '2024-01-15',
    ]);

    ($this->seedBatchItem)($payment->legacy_uid, '1001', 100);
    ($this->seedBatchItem)($payment->legacy_uid, '1002', 50);

    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($older->fresh()->is_settled)->toBeTrue()
        ->and($newer->fresh()->is_settled)->toBeFalse();

    expect((float) PaymentAllocation::where('document_id', $older->id)->sum('allocated_amount'))->toBe(100.0)
        ->and((float) PaymentAllocation::where('document_id', $newer->id)->sum('allocated_amount'))->toBe(50.0);
});

test('sums two batch items against the same invoice into one allocation row instead of violating the unique constraint', function () {
    ($this->seedInvoiceEntry)(120, '1201', 0.00);

    $document = Document::factory()->create([
        'legacy_uid' => 120, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);

    $payment = Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'legacy_uid' => 5020, 'customer_id' => $this->customer->id,
        'amount' => 100, 'payment_date' => '2024-01-01',
    ]);

    // Two separate legacy batch lines posted against the same invoice in one batch.
    ($this->seedBatchItem)($payment->legacy_uid, '1201', 60);
    ($this->seedBatchItem)($payment->legacy_uid, '1201', 40);

    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect(PaymentAllocation::where('payment_id', $payment->id)->where('document_id', $document->id)->count())->toBe(1);
    expect((float) PaymentAllocation::where('document_id', $document->id)->sum('allocated_amount'))->toBe(100.0);
});

test('applies the entryvalue sign multiplier from AccountPostTypes to paymt', function () {
    ($this->seedInvoiceEntry)(110, '1101', 0.00);

    $document = Document::factory()->create([
        'legacy_uid' => 110, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);

    $payment = Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'legacy_uid' => 5010, 'customer_id' => $this->customer->id,
        'amount' => 100, 'payment_date' => '2024-01-01',
    ]);

    // posttype 25 has entryvalue=-1, so a negative paymt of -100 applies as +100.
    ($this->seedBatchItem)($payment->legacy_uid, '1101', -100, posttype: 25);

    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect((float) PaymentAllocation::where('document_id', $document->id)->sum('allocated_amount'))->toBe(100.0);
});

test('reports a payment with no legacy allocation record instead of guessing', function () {
    ($this->seedInvoiceEntry)(201, '2001', 20.00);

    $document = Document::factory()->create([
        'legacy_uid' => 201, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);

    Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'legacy_uid' => 5002, 'customer_id' => $this->customer->id,
        'amount' => 30, 'payment_date' => '2024-01-10',
    ]);

    // Nothing resolves and nothing is settled/unsettled, so there's nothing to write —
    // the command reports the gap and exits without prompting to apply changes.
    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsOutputToContain('Unallocated Payments')
        ->assertSuccessful();

    expect($document->fresh()->is_settled)->toBeFalse();

    expect(PaymentAllocation::count())->toBe(0);
});

test('excludes an invoice whose legacy ref collides with another document and leaves it unsettled', function () {
    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 301, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '3001', 'bline' => 0],
        ['uid' => 302, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-02-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '3001', 'bline' => 0],
    ]);
    DB::connection('legacy')->table('AccountEntries')->insert([
        'uid' => 90301, 'rtype' => 'a', 'custid' => 1, 'posttype' => 85, 'invno' => '3001', 'osvalue' => 0,
    ]);

    $documentA = Document::factory()->create([
        'legacy_uid' => 301, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);
    $documentB = Document::factory()->create([
        'legacy_uid' => 302, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);

    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($documentA->fresh()->is_settled)->toBeFalse()
        ->and($documentB->fresh()->is_settled)->toBeFalse();
    expect(PaymentAllocation::count())->toBe(0);
});

test('dry-run writes nothing', function () {
    ($this->seedInvoiceEntry)(401, '4001', 0.00);

    $document = Document::factory()->create([
        'legacy_uid' => 401, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);

    $payment = Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'legacy_uid' => 5004, 'customer_id' => $this->customer->id, 'amount' => 100, 'payment_date' => '2024-01-01',
    ]);
    ($this->seedBatchItem)($payment->legacy_uid, '4001', 100);

    $this->artisan('payments:reconcile-legacy-allocations', ['--dry-run' => true])
        ->assertSuccessful();

    expect($document->fresh()->is_settled)->toBeFalse();
    expect(PaymentAllocation::count())->toBe(0);
});

test('re-running is idempotent and never touches allocations belonging to a live-created (non-legacy) payment', function () {
    ($this->seedInvoiceEntry)(501, '5001', 0.00);

    $document = Document::factory()->create([
        'legacy_uid' => 501, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);

    $migratedPayment = Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'legacy_uid' => 5005, 'customer_id' => $this->customer->id, 'amount' => 100, 'payment_date' => '2024-01-01',
    ]);
    ($this->seedBatchItem)($migratedPayment->legacy_uid, '5001', 100);

    $liveDocument = Document::factory()->create([
        'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 50,
    ]);
    $livePayment = Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'customer_id' => $this->customer->id, 'amount' => 50, 'payment_date' => '2024-01-01',
    ]);
    PaymentAllocation::create([
        'payment_id' => $livePayment->id, 'document_id' => $liveDocument->id, 'allocated_amount' => 50,
    ]);

    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect(PaymentAllocation::where('payment_id', $migratedPayment->id)->count())->toBe(1);
    expect(PaymentAllocation::where('payment_id', $livePayment->id)->where('document_id', $liveDocument->id)->exists())->toBeTrue();
});
