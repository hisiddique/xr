<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents']);

    Schema::connection('legacy')->create('AccountEntries', function (Blueprint $table): void {
        $table->unsignedBigInteger('uid');
        $table->string('rtype', 1);
        $table->unsignedBigInteger('posttype')->nullable();
        $table->string('invno')->nullable();
        $table->decimal('osvalue', 12, 2)->nullable();
    });

    $this->customer = Customer::factory()->create(['legacy_uid' => 1]);
    $this->paymentMethod = LookupPaymentMethod::create(['name' => 'Cash']);

    $this->seedInvoiceEntry = function (int $uid, string $ref, float $osvalue): void {
        DB::connection('legacy')->table('Documents')->insert([
            'uid' => $uid, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
            'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
            'ref' => $ref, 'bline' => 0,
        ]);

        DB::connection('legacy')->table('AccountEntries')->insert([
            'uid' => 90000 + $uid, 'rtype' => 'a', 'posttype' => 85, 'invno' => $ref, 'osvalue' => $osvalue,
        ]);
    };
});

test('funds the older invoice fully before the newer one, from oldest payments first', function () {
    ($this->seedInvoiceEntry)(101, '1001', 0.00); // fully paid: total 100, osvalue 0
    ($this->seedInvoiceEntry)(102, '1002', 50.00); // half outstanding: total 100, osvalue 50

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

    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($older->fresh()->is_settled)->toBeTrue()
        ->and($newer->fresh()->is_settled)->toBeFalse();

    $olderAllocated = PaymentAllocation::where('document_id', $older->id)->sum('allocated_amount');
    $newerAllocated = PaymentAllocation::where('document_id', $newer->id)->sum('allocated_amount');

    expect((float) $olderAllocated)->toBe(100.0)
        ->and((float) $newerAllocated)->toBe(50.0);
});

test('reports a shortfall without force-balancing when payments run out', function () {
    ($this->seedInvoiceEntry)(201, '2001', 20.00); // total 100, osvalue 20 -> target 80

    $document = Document::factory()->create([
        'legacy_uid' => 201, 'customer_id' => $this->customer->id,
        'type' => 'INV', 'doc_date' => '2024-01-01', 'total_value' => 100,
    ]);

    Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'legacy_uid' => 5002, 'customer_id' => $this->customer->id,
        'amount' => 30, 'payment_date' => '2024-01-10',
    ]);

    $this->artisan('payments:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    // is_settled is driven by legacy osvalue directly (still 20 outstanding), not by the funding pass.
    expect($document->fresh()->is_settled)->toBeFalse();

    $allocated = PaymentAllocation::where('document_id', $document->id)->sum('allocated_amount');
    expect((float) $allocated)->toBe(30.0);
});

test('excludes an invoice whose legacy ref collides with another document and leaves it unsettled', function () {
    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 301, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '3001', 'bline' => 0],
        ['uid' => 302, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null, 'date' => '2024-02-01', 'goods' => 0, 'value' => 0, 'notes' => null, 'ref' => '3001', 'bline' => 0],
    ]);
    DB::connection('legacy')->table('AccountEntries')->insert([
        'uid' => 90301, 'rtype' => 'a', 'posttype' => 85, 'invno' => '3001', 'osvalue' => 0,
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

    Payment::factory()->create([
        'payment_method_id' => $this->paymentMethod->id, 'legacy_uid' => 5004, 'customer_id' => $this->customer->id, 'amount' => 100, 'payment_date' => '2024-01-01',
    ]);

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
