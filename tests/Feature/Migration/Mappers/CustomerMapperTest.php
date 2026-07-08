<?php

use App\Models\Customer;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Mappers\CustomerMapper;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    useLegacyDatabase();
    createLegacyTables(['CustSupps']);

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
        'term' => 30,
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

test('re-running apply with SkipExisting leaves the existing customer untouched', function () {
    $this->mapper->apply($this->legacyRow, DuplicateStrategy::UpdateExisting);

    $changedRow = $this->legacyRow;
    $changedRow['town'] = 'Manchester';

    $outcome = $this->mapper->apply($changedRow, DuplicateStrategy::SkipExisting);

    expect($outcome)->toBe(MapOutcome::Skipped);
    expect(Customer::count())->toBe(1);
    expect(Customer::first()->town)->toBe('London');
});
