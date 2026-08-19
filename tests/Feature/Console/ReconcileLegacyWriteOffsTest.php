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

    $this->seedBatchItem = function (int $cshuid, string $ref, float $paymt, ?int $posttype = null): void {
        DB::connection('legacy')->table('AccountBatchItems')->insert([
            'bhead' => 1, 'bline' => 1, 'cshuid' => $cshuid, 'txnabbr' => 'INV-', 'txnref' => $ref, 'paymt' => $paymt, 'posttype' => $posttype,
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
