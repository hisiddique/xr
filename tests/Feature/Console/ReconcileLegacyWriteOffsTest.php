<?php

use App\Models\Customer;
use App\Models\Document;
use App\Models\User;
use App\Models\WriteOff;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['Documents', 'AccountEntries', 'AccountPostTypes', 'AccountBatchItems']);

    DB::connection('legacy')->table('AccountPostTypes')->insert([
        ['uid' => 25, 'rtype' => 'A', 'inout' => 'IN', 'entryvalue' => -1],
    ]);

    $this->customer = Customer::factory()->create(['legacy_uid' => 1]);
    $this->admin = User::factory()->admin()->create();

    $this->seedInvoice = function (int $uid, string $ref): void {
        DB::connection('legacy')->table('Documents')->insert([
            'uid' => $uid, 'rtype' => 'i', 'acctuid' => 1, 'orderno' => null,
            'date' => '2024-01-01', 'goods' => 0, 'value' => 0, 'notes' => null,
            'ref' => $ref, 'bline' => 0,
        ]);
    };

    $this->seedWriteOffEntry = function (int $uid, string $txndate = '2024-03-01'): void {
        DB::connection('legacy')->table('AccountEntries')->insert([
            'uid' => $uid, 'rtype' => 'a', 'custid' => 1, 'posttype' => 94, 'invno' => "W'Off", 'osvalue' => 0, 'txndate' => $txndate,
        ]);
    };

    $this->seedBatchItem = function (int $cshuid, string $ref, float $paymt, ?int $posttype = null, ?string $txndetails = null): void {
        DB::connection('legacy')->table('AccountBatchItems')->insert([
            'bhead' => 1, 'bline' => 1, 'cshuid' => $cshuid, 'txnabbr' => 'INV-', 'txnref' => $ref, 'txndetails' => $txndetails, 'paymt' => $paymt, 'posttype' => $posttype,
        ]);
    };
});

test('migrates a legacy write-off to the invoice its batch item resolves to', function () {
    ($this->seedInvoice)(101, '1001');
    ($this->seedWriteOffEntry)(9001, '2024-03-05');
    ($this->seedBatchItem)(9001, '1001', -25.50, posttype: 25);

    $invoice = Document::factory()->create([
        'legacy_uid' => 101, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100,
    ]);

    $this->artisan('write-offs:reconcile-legacy')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    $writeOff = WriteOff::where('legacy_uid', 9001)->first();
    expect($writeOff)->not->toBeNull()
        ->and($writeOff->document_id)->toBe($invoice->id)
        ->and((float) $writeOff->amount)->toBe(25.50)
        ->and($writeOff->written_off_at->format('Y-m-d'))->toBe('2024-03-05')
        ->and($writeOff->reason)->not->toBeEmpty();
});

test('splits one legacy write-off across two invoices into two write-off rows', function () {
    ($this->seedInvoice)(110, '1101');
    ($this->seedInvoice)(111, '1102');
    ($this->seedWriteOffEntry)(9010, '2024-03-10');
    ($this->seedBatchItem)(9010, '1101', 5);
    ($this->seedBatchItem)(9010, '1102', 7.5);

    $invoiceA = Document::factory()->create(['legacy_uid' => 110, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);
    $invoiceB = Document::factory()->create(['legacy_uid' => 111, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);

    $this->artisan('write-offs:reconcile-legacy')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect(WriteOff::where('legacy_uid', 9010)->count())->toBe(2);
    expect((float) WriteOff::where('legacy_uid', 9010)->where('document_id', $invoiceA->id)->value('amount'))->toBe(5.0);
    expect((float) WriteOff::where('legacy_uid', 9010)->where('document_id', $invoiceB->id)->value('amount'))->toBe(7.5);
});

test('sums two batch items against the same invoice into one write-off row instead of violating the unique constraint', function () {
    ($this->seedInvoice)(112, '1103');
    ($this->seedWriteOffEntry)(9011);
    ($this->seedBatchItem)(9011, '1103', 3);
    ($this->seedBatchItem)(9011, '1103', 2);

    $invoice = Document::factory()->create(['legacy_uid' => 112, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);

    $this->artisan('write-offs:reconcile-legacy')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect(WriteOff::where('legacy_uid', 9011)->where('document_id', $invoice->id)->count())->toBe(1);
    expect((float) WriteOff::where('legacy_uid', 9011)->value('amount'))->toBe(5.0);
});

test('resolves a bulk batch-labeled write-off via txndetails when txnref only holds the batch label', function () {
    ($this->seedInvoice)(113, '830101');
    ($this->seedWriteOffEntry)(9012);
    ($this->seedBatchItem)(9012, 'c/b 200', 9.5, txndetails: '830101');

    $invoice = Document::factory()->create(['legacy_uid' => 113, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);

    $this->artisan('write-offs:reconcile-legacy')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    expect((float) WriteOff::where('legacy_uid', 9012)->where('document_id', $invoice->id)->value('amount'))->toBe(9.5);
});

test('reports a write-off with no batch item instead of guessing', function () {
    ($this->seedWriteOffEntry)(9002);

    $this->artisan('write-offs:reconcile-legacy')
        ->expectsOutputToContain('Unresolved write-offs')
        ->assertSuccessful();

    expect(WriteOff::count())->toBe(0);
});

test('dry-run writes nothing', function () {
    ($this->seedInvoice)(102, '1002');
    ($this->seedWriteOffEntry)(9003);
    ($this->seedBatchItem)(9003, '1002', 10);

    Document::factory()->create(['legacy_uid' => 102, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);

    $this->artisan('write-offs:reconcile-legacy', ['--dry-run' => true])
        ->assertSuccessful();

    expect(WriteOff::count())->toBe(0);
});

test('re-running does not duplicate an already-migrated write-off', function () {
    ($this->seedInvoice)(103, '1003');
    ($this->seedWriteOffEntry)(9004);
    ($this->seedBatchItem)(9004, '1003', 15);

    Document::factory()->create(['legacy_uid' => 103, 'customer_id' => $this->customer->id, 'type' => 'INV', 'total_value' => 100]);

    $this->artisan('write-offs:reconcile-legacy')
        ->expectsConfirmation('Apply these changes?', 'yes')
        ->assertSuccessful();

    $this->artisan('write-offs:reconcile-legacy')
        ->assertSuccessful();

    expect(WriteOff::where('legacy_uid', 9004)->count())->toBe(1);
});
