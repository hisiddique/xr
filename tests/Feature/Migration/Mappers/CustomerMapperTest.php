<?php

use App\Models\Customer;
use App\Models\LookupCreditTerm;
use App\Models\User;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\CustomerMapper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['CustSupps', 'AppCodes']);

    DB::connection('legacy')->table('AppCodes')->insert([
        ['codetype' => 'Cust-CreditTerms', 'valueint' => 3, 'description' => 'Net 30 days.'],
    ]);

    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 101,
        'rtype' => 'A',
        'name' => 'Acme Ltd',
        'add1' => '1 High Street',
        'add2' => null,
        'town' => 'London',
        'pcode' => 'EC1A 1AA',
        'email' => 'acme@example.com',
        'disc' => 5,
        'vatcode' => '1',
        'vatdiff' => 0,
        'crlim' => 1000,
        'term' => 3,
        'createddate' => 'Nov 11 2019 09:30:00:AM',
        'modifieddate' => 'Mar 3 2021 14:15:00',
    ]);

    $this->mapper = new CustomerMapper;
    $this->legacyRow = (array) DB::connection('legacy')->table('CustSupps')->where('uid', 101)->first();
});

test('count only counts active customer rows', function () {
    expect($this->mapper->count())->toBe(1);
});

test('fresh apply with UpdateExisting creates a customer with mapped fields', function () {
    $outcome = $this->mapper->apply($this->legacyRow, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Added);

    $customer = Customer::where('company_name', 'Acme Ltd')->first();

    expect($customer)->not->toBeNull()
        ->and($customer->legacy_uid)->toBe(101)
        ->and($customer->company_name)->toBe('Acme Ltd')
        ->and($customer->email_1)->toBe('acme@example.com')
        ->and($customer->vat_registered)->toBeTrue();
});

test('re-running apply with UpdateExisting updates the existing customer instead of duplicating', function () {
    $this->mapper->apply($this->legacyRow, DuplicateStrategy::UpdateExisting);

    $changedRow = $this->legacyRow;
    $changedRow['town'] = 'Manchester';

    $outcome = $this->mapper->apply($changedRow, DuplicateStrategy::UpdateExisting);

    expect($outcome)->toBe(MapOutcome::Updated);
    expect(Customer::count())->toBe(1);
    expect(Customer::first()->town)->toBe('Manchester');
});

test('apply attributes the migrated customer to the admin running the migration', function () {
    $admin = User::factory()->create();

    $this->mapper->setCreatedBy($admin->id);
    $this->mapper->apply($this->legacyRow, DuplicateStrategy::UpdateExisting);

    expect(Customer::first()->created_by)->toBe($admin->id);
});

test('apply maps legacy createddate/modifieddate to created_at/updated_at', function () {
    $this->mapper->apply($this->legacyRow, DuplicateStrategy::UpdateExisting);

    $customer = Customer::first();

    expect($customer->created_at->toDateTimeString())->toBe('2019-11-11 09:30:00')
        ->and($customer->updated_at->toDateTimeString())->toBe('2021-03-03 14:15:00');
});

test('apply falls back to now for created_at when legacy createddate is missing', function () {
    $row = $this->legacyRow;
    $row['createddate'] = null;
    $row['modifieddate'] = null;

    $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect(Customer::first()->created_at)->not->toBeNull();
});

test('apply resolves credit_term_id via the Cust-CreditTerms AppCodes lookup', function () {
    $this->mapper->apply($this->legacyRow, DuplicateStrategy::UpdateExisting);

    $customer = Customer::first();
    $creditTerm = LookupCreditTerm::find($customer->credit_term_id);

    expect($creditTerm)->not->toBeNull()
        ->and($creditTerm->name)->toBe('Net 30 days.');
});

test('apply leaves credit_term_id null when Term has no matching AppCodes entry', function () {
    $row = $this->legacyRow;
    $row['term'] = 999;

    $this->mapper->apply($row, DuplicateStrategy::UpdateExisting);

    expect(Customer::first()->credit_term_id)->toBeNull();
});

test('re-running apply with SkipExisting leaves the existing customer untouched', function () {
    $this->mapper->apply($this->legacyRow, DuplicateStrategy::UpdateExisting);

    $changedRow = $this->legacyRow;
    $changedRow['town'] = 'Manchester';

    $outcome = $this->mapper->apply($changedRow, DuplicateStrategy::SkipExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(Customer::count())->toBe(1);
    expect(Customer::first()->town)->toBe('London');
});
