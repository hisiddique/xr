<?php

use App\Models\Customer;
use App\Models\LookupPaymentMethod;
use App\Models\Payment;
use App\Models\User;
use App\PaymentSourceType;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\PaymentMapper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['AccountEntries', 'AccountPostTypes', 'CustSupps']);

    DB::connection('legacy')->table('AccountPostTypes')->insert([
        ['uid' => 85, 'rtype' => 'i', 'inout' => 'OUT'],
        ['uid' => 86, 'rtype' => 'r', 'inout' => 'OUT'],
        ['uid' => 94, 'rtype' => 'A', 'inout' => ''],
        ['uid' => 11, 'rtype' => 'A', 'inout' => 'IN'],
        ['uid' => 20, 'rtype' => 'A', 'inout' => 'IN'],
    ]);

    foreach (['Bank Transfer', 'Cheque', 'Cash', 'Card'] as $name) {
        LookupPaymentMethod::create(['name' => $name]);
    }

    $this->user = User::factory()->create();
    $this->mapper = (new PaymentMapper)->setCreatedBy($this->user->id);

    $this->insertReceipt = function (array $overrides = []): void {
        DB::connection('legacy')->table('AccountEntries')->insert(array_merge([
            'uid' => 1001,
            'rtype' => 'a',
            'custid' => 501,
            'value' => -100.00,
            'osvalue' => 0,
            'txndate' => '2024-01-15',
            'invno' => 'BACS',
            'posttype' => 11,
            'createddate' => null,
            'modifieddate' => null,
        ], $overrides));
    };
});

test('rows/count only include receipts joined to an inout=IN posttype for rtype=a', function () {
    ($this->insertReceipt)(['uid' => 1, 'posttype' => 11]); // real receipt
    ($this->insertReceipt)(['uid' => 2, 'posttype' => 85]); // invoice posting, excluded
    ($this->insertReceipt)(['uid' => 3, 'rtype' => 'b', 'posttype' => 11]); // supplier side, excluded

    expect($this->mapper->count())->toBe(1);

    $rows = collect($this->mapper->rows(500))->all();
    expect($rows)->toHaveCount(1);
    expect($rows[0]['uid'])->toBe(1);
});

test('excludedCount reports receipts whose customer does not exist in legacy at all, without fetching them', function () {
    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 601, 'rtype' => 'A', 'name' => 'Real Customer',
    ]);

    ($this->insertReceipt)(['uid' => 11, 'custid' => 601]);
    ($this->insertReceipt)(['uid' => 12, 'custid' => 999999]);

    expect($this->mapper->excludedCount())->toBe(1);
    expect($this->mapper->count())->toBe(2);
});

test('apply skips a receipt whose customer has not been migrated yet', function () {
    ($this->insertReceipt)(['uid' => 21, 'custid' => 999]);

    $row = (array) DB::connection('legacy')->table('AccountEntries')->where('uid', 21)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(Payment::count())->toBe(0);
});

test('apply skips a receipt with a zero value', function () {
    Customer::factory()->create(['legacy_uid' => 501]);

    ($this->insertReceipt)(['uid' => 22, 'value' => 0]);

    $row = (array) DB::connection('legacy')->table('AccountEntries')->where('uid', 22)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(Payment::count())->toBe(0);
});

test('apply skips a receipt with an unparseable txndate', function () {
    Customer::factory()->create(['legacy_uid' => 501]);

    ($this->insertReceipt)(['uid' => 23, 'txndate' => 'not-a-date']);

    $row = (array) DB::connection('legacy')->table('AccountEntries')->where('uid', 23)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(Payment::count())->toBe(0);
});

test('transform resolves amount as the absolute value of a negative legacy value', function () {
    Customer::factory()->create(['legacy_uid' => 501]);

    ($this->insertReceipt)(['uid' => 24, 'value' => -456.78]);

    $row = (array) DB::connection('legacy')->table('AccountEntries')->where('uid', 24)->first();

    expect($this->mapper->transform($row)['amount'])->toEqual(456.78);
});

test('transform infers payment method from the Invno prefix, defaulting to Cash', function (string $invno, string $expectedMethod) {
    Customer::factory()->create(['legacy_uid' => 501]);

    ($this->insertReceipt)(['uid' => 25, 'invno' => $invno]);

    $row = (array) DB::connection('legacy')->table('AccountEntries')->where('uid', 25)->first();

    $methodId = $this->mapper->transform($row)['payment_method_id'];

    expect(LookupPaymentMethod::find($methodId)->name)->toBe($expectedMethod);
})->with([
    ['BACS PDQ 1', 'Bank Transfer'],
    ['PDQ 137', 'Card'],
    ['CHQ-4521', 'Cheque'],
    ['cash', 'Cash'],
    ['C/B 56', 'Cash'],
]);

test('apply creates a Payment with a PAY-L-prefixed reference and preserves the legacy free text separately', function () {
    Customer::factory()->create(['legacy_uid' => 501]);

    ($this->insertReceipt)(['uid' => 26, 'invno' => 'BACS  ']);

    $row = (array) DB::connection('legacy')->table('AccountEntries')->where('uid', 26)->first();

    $outcome = $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Added);

    $payment = Payment::where('legacy_uid', 26)->first();

    expect($payment)->not->toBeNull()
        ->and($payment->reference)->toStartWith('PAY-L-')
        ->and($payment->payment_reference)->toBe('BACS')
        ->and($payment->source_type)->toBe(PaymentSourceType::Cash)
        ->and($payment->is_exhausted)->toBeFalse();
});

test('apply carries legacy Createddate/Modifieddate through to created_at/updated_at', function () {
    Customer::factory()->create(['legacy_uid' => 501]);

    ($this->insertReceipt)([
        'uid' => 27,
        'createddate' => 'Nov 22 2016 12:00:00:AM',
        'modifieddate' => 'Dec  1 2016 12:00:00:AM',
    ]);

    $row = (array) DB::connection('legacy')->table('AccountEntries')->where('uid', 27)->first();

    $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    $payment = Payment::where('legacy_uid', 27)->first();

    expect($payment->created_at->format('Y-m-d'))->toBe('2016-11-22')
        ->and($payment->updated_at->format('Y-m-d'))->toBe('2016-12-01');
});
