<?php

use App\Models\CreditAllocation;
use App\Models\Customer;
use App\Models\Document;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents']);

    $this->customer = Customer::factory()->create(['legacy_uid' => 1]);

    $this->seedInvoice = function (int $uid, string $ref): void {
        DB::connection('legacy')->table('Documents')->insert([
            'uid' => $uid, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
            'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
            'ref' => $ref, 'bline' => 0,
        ]);
    };

    $this->seedCreditNote = function (int $uid, string $ref, ?string $srcabbr, ?string $srcref): void {
        DB::connection('legacy')->table('Documents')->insert([
            'uid' => $uid, 'rtype' => 'r', 'acctuid' => 1, 'orderno' => null,
            'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
            'ref' => $ref, 'bline' => 0, 'srcabbr' => $srcabbr, 'srcref' => $srcref,
        ]);
    };
});

test('links a migrated credit note to its source invoice using srcref, not a guess', function () {
    ($this->seedInvoice)(101, '1001');
    ($this->seedCreditNote)(201, '2001', 'INV-', '1001');

    $invoice = Document::factory()->create([
        'legacy_uid' => 101, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);
    $creditNote = Document::factory()->create([
        'legacy_uid' => 201, 'customer_id' => $this->customer->id, 'type' => 'CN', 'total_value' => 30,
    ]);

    $this->artisan('credit-notes:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($creditNote->fresh()->credited_invoice_id)->toBe($invoice->id);

    $allocation = CreditAllocation::where('credit_note_id', $creditNote->id)->first();
    expect($allocation)->not->toBeNull()
        ->and($allocation->invoice_id)->toBe($invoice->id)
        ->and($allocation->payment_id)->toBeNull()
        ->and((float) $allocation->amount)->toBe(30.0);
});

test('reports a credit note with no source reference instead of guessing', function () {
    ($this->seedCreditNote)(202, '2002', null, null);

    $creditNote = Document::factory()->create([
        'legacy_uid' => 202, 'customer_id' => $this->customer->id, 'type' => 'CN', 'total_value' => 30,
    ]);

    $this->artisan('credit-notes:reconcile-legacy-allocations')
        ->expectsOutputToContain('Unresolved credit notes')
        ->assertSuccessful();

    expect($creditNote->fresh()->credited_invoice_id)->toBeNull();
    expect(CreditAllocation::count())->toBe(0);
});

test('reports a credit note whose srcref collides with more than one invoice', function () {
    ($this->seedInvoice)(103, '1003');
    ($this->seedInvoice)(104, '1003');
    ($this->seedCreditNote)(203, '2003', 'INV-', '1003');

    Document::factory()->create(['legacy_uid' => 103, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);
    Document::factory()->create(['legacy_uid' => 104, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);
    $creditNote = Document::factory()->create(['legacy_uid' => 203, 'customer_id' => $this->customer->id, 'type' => 'CN', 'total_value' => 30]);

    $this->artisan('credit-notes:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect($creditNote->fresh()->credited_invoice_id)->toBeNull();
    expect(CreditAllocation::count())->toBe(0);
});

test('dry-run writes nothing', function () {
    ($this->seedInvoice)(105, '1005');
    ($this->seedCreditNote)(205, '2005', 'INV-', '1005');

    $creditNote = Document::factory()->create([
        'legacy_uid' => 205, 'customer_id' => $this->customer->id, 'type' => 'CN', 'total_value' => 30,
    ]);
    Document::factory()->create(['legacy_uid' => 105, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);

    $this->artisan('credit-notes:reconcile-legacy-allocations', ['--dry-run' => true])
        ->assertSuccessful();

    expect($creditNote->fresh()->credited_invoice_id)->toBeNull();
    expect(CreditAllocation::count())->toBe(0);
});

test('re-running is idempotent and never touches a live-created (non-legacy) credit allocation', function () {
    ($this->seedInvoice)(106, '1006');
    ($this->seedCreditNote)(206, '2006', 'INV-', '1006');

    $invoice = Document::factory()->create(['legacy_uid' => 106, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);
    $creditNote = Document::factory()->create(['legacy_uid' => 206, 'customer_id' => $this->customer->id, 'type' => 'CN', 'total_value' => 30]);

    $liveInvoice = Document::factory()->create(['customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 50]);
    $liveCreditNote = Document::factory()->create(['customer_id' => $this->customer->id, 'type' => 'CN', 'total_value' => 20]);
    CreditAllocation::create([
        'payment_id' => null, 'credit_note_id' => $liveCreditNote->id, 'invoice_id' => $liveInvoice->id, 'amount' => 20,
    ]);

    $this->artisan('credit-notes:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    $this->artisan('credit-notes:reconcile-legacy-allocations')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect(CreditAllocation::where('credit_note_id', $creditNote->id)->count())->toBe(1);
    expect(CreditAllocation::where('credit_note_id', $liveCreditNote->id)->where('invoice_id', $liveInvoice->id)->exists())->toBeTrue();
});
