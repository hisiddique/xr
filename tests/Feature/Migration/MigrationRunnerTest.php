<?php

use App\MigrationRunStatus;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\LookupUnit;
use App\Models\MigrationRun;
use App\Models\Setting;
use App\Models\User;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MigrationRunner;
use App\UserRole;
use Illuminate\Support\Facades\DB;

/**
 * Seeds a small legacy dataset covering Units, CustSupps, Documents, DocumentDetails,
 * Companies and CompanySettings — enough for `customers` and `delivery_documents`
 * groups plus SettingsMapper to run without error.
 */
function seedLegacyMigrationDataset(): void
{
    createLegacyTables(['Units', 'CustSupps', 'Documents', 'DocumentDetails', 'Companies', 'CompanySettings']);

    DB::connection('legacy')->table('Units')->insert([
        ['uid' => 1, 'name' => 'Box', 'status' => 'S', 'recstate' => 'A'],
        ['uid' => 2, 'name' => 'Pallet', 'status' => 'S', 'recstate' => 'A'],
    ]);

    DB::connection('legacy')->table('CustSupps')->insert([
        ['uid' => 201, 'rtype' => 'A', 'name' => 'Cust A', 'add1' => '1 Road', 'town' => 'Town1', 'pcode' => 'AA1 1AA', 'email' => 'a@test.com', 'disc' => 0, 'vatcode' => null, 'vatdiff' => 0, 'crlim' => 0, 'term' => null],
        ['uid' => 202, 'rtype' => 'A', 'name' => 'Cust B', 'add1' => '2 Road', 'town' => 'Town2', 'pcode' => 'BB2 2BB', 'email' => 'b@test.com', 'disc' => 0, 'vatcode' => null, 'vatdiff' => 0, 'crlim' => 0, 'term' => null],
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        ['uid' => 301, 'rtype' => 'd', 'acctuid' => 201, 'orderno' => 'PO1', 'date' => '2024-01-01', 'goods' => 100, 'value' => 120, 'notes' => null, 'ref' => '9001', 'bline' => 10],
        ['uid' => 302, 'rtype' => 'i', 'acctuid' => 202, 'orderno' => 'PO2', 'date' => '2024-01-05', 'goods' => 200, 'value' => 240, 'notes' => null, 'ref' => '9002', 'bline' => 11],
    ]);

    DB::connection('legacy')->table('DocumentDetails')->insert([
        ['uid' => 401, 'rtype' => 'd', 'bline' => 10, 'details' => 'Item A', 'qty' => 1, 'price' => 100, 'unitdesc' => 'Each', 'value' => 100],
        ['uid' => 402, 'rtype' => 'i', 'bline' => 11, 'details' => 'Item B', 'qty' => 2, 'price' => 100, 'unitdesc' => 'Each', 'value' => 200],
    ]);

    DB::connection('legacy')->table('Companies')->insert([
        'CompanyName' => 'Test Co', 'add1' => '1 HQ Road', 'add2' => null, 'county' => 'Countyshire',
        'pcode' => 'HQ1 1HQ', 'country' => 'UK', 'email_sales' => 'sales@testco.com', 'email_accounts' => null,
    ]);
}

beforeEach(function () {
    useLegacyDatabase();
    seedLegacyMigrationDataset();

    $this->admin = User::factory()->admin()->create();
});

test('fresh import processes lookups, settings, customers and documents', function () {
    $run = MigrationRun::create(['status' => MigrationRunStatus::Pending]);

    (new MigrationRunner($run))->run(
        ['customers', 'delivery_documents'],
        DuplicateStrategy::UpdateExisting,
        'none',
        $this->admin->id,
    );

    $run->refresh();

    expect($run->status)->toBe(MigrationRunStatus::Completed);

    $entities = $run->tables()->orderBy('id')->pluck('added', 'entity');

    expect($entities->keys()->all())->toEqual(['lookups', 'settings', 'customers', 'documents', 'document_items']);
    expect($entities['lookups'])->toBe(2);
    expect($entities['customers'])->toBe(2);
    expect($entities['documents'])->toBe(2);
    expect($entities['document_items'])->toBe(2);

    expect(Customer::count())->toBe(2);
    expect(Document::count())->toBe(2);
    expect(DocumentItem::count())->toBe(2);
    expect(Customer::where('legacy_uid', 201)->exists())->toBeTrue();
    expect(Document::where('legacy_uid', 301)->exists())->toBeTrue();
});

test('re-running with UpdateExisting updates rather than duplicates', function () {
    $run1 = MigrationRun::create(['status' => MigrationRunStatus::Pending]);
    (new MigrationRunner($run1))->run(['customers', 'delivery_documents'], DuplicateStrategy::UpdateExisting, 'none', $this->admin->id);

    $run2 = MigrationRun::create(['status' => MigrationRunStatus::Pending]);
    (new MigrationRunner($run2))->run(['customers', 'delivery_documents'], DuplicateStrategy::UpdateExisting, 'none', $this->admin->id);

    $run2->refresh();

    expect($run2->status)->toBe(MigrationRunStatus::Completed);
    expect(Customer::count())->toBe(2);
    expect(Document::count())->toBe(2);
    expect(DocumentItem::count())->toBe(2);

    $entities = $run2->tables()->pluck('updated', 'entity');
    expect($entities['customers'])->toBe(2);
    expect($entities['documents'])->toBe(2);
});

test('re-running with SkipExisting leaves target rows unchanged', function () {
    $run1 = MigrationRun::create(['status' => MigrationRunStatus::Pending]);
    (new MigrationRunner($run1))->run(['customers', 'delivery_documents'], DuplicateStrategy::UpdateExisting, 'none', $this->admin->id);

    $run2 = MigrationRun::create(['status' => MigrationRunStatus::Pending]);
    (new MigrationRunner($run2))->run(['customers', 'delivery_documents'], DuplicateStrategy::SkipExisting, 'none', $this->admin->id);

    $run2->refresh();

    expect($run2->status)->toBe(MigrationRunStatus::Completed);
    expect(Customer::count())->toBe(2);
    expect(Document::count())->toBe(2);

    $entities = $run2->tables()->pluck('skipped', 'entity');
    expect($entities['customers'])->toBe(2);
    expect($entities['documents'])->toBe(2);
});

test('clearing migrated-only data then re-importing avoids duplicates', function () {
    $run1 = MigrationRun::create(['status' => MigrationRunStatus::Pending]);
    (new MigrationRunner($run1))->run(['customers', 'delivery_documents'], DuplicateStrategy::UpdateExisting, 'none', $this->admin->id);

    $firstCustomerId = Customer::where('legacy_uid', 201)->value('id');
    $firstDocumentId = Document::where('legacy_uid', 301)->value('id');

    $run2 = MigrationRun::create(['status' => MigrationRunStatus::Pending]);
    (new MigrationRunner($run2))->run(['customers', 'delivery_documents'], DuplicateStrategy::UpdateExisting, 'migrated_only', $this->admin->id);

    $run2->refresh();

    expect($run2->status)->toBe(MigrationRunStatus::Completed);
    expect(Customer::count())->toBe(2);
    expect(Document::count())->toBe(2);

    $newCustomerId = Customer::where('legacy_uid', 201)->value('id');
    $newDocumentId = Document::where('legacy_uid', 301)->value('id');

    expect($newCustomerId)->not->toBe($firstCustomerId);
    expect($newDocumentId)->not->toBe($firstDocumentId);
});

/**
 * Forces the failure on CustomerMapper directly: the `customers` table's
 * `company_name` column is NOT NULL, and CustomerMapper maps a blank legacy
 * name straight through to null, so a row with no name reliably violates the
 * DB constraint at Customer::create() time.
 */
test('a failing row does not halt earlier successful entities and leaves valid rows in the same chunk intact', function () {
    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 203, 'rtype' => 'A', 'name' => null, 'add1' => null, 'town' => null,
        'pcode' => null, 'email' => null, 'disc' => 0, 'vatcode' => null, 'vatdiff' => 0, 'crlim' => 0, 'term' => null,
    ]);

    $run = MigrationRun::create(['status' => MigrationRunStatus::Pending]);

    (new MigrationRunner($run))->run(['customers'], DuplicateStrategy::UpdateExisting, 'none', $this->admin->id);

    $run->refresh();

    expect($run->status)->toBe(MigrationRunStatus::Failed);

    $tables = $run->tables()->get()->keyBy('entity');

    expect($tables['lookups']->status)->toBe(MigrationRunStatus::Completed);
    expect($tables['settings']->status)->toBe(MigrationRunStatus::Completed);
    expect($tables['customers']->status)->toBe(MigrationRunStatus::Failed);
    expect($tables['customers']->failed)->toBeGreaterThanOrEqual(1);
    expect($tables['customers']->added)->toBe(2);

    expect(LookupUnit::count())->toBe(2);
    expect(Setting::get('company_name'))->toBe('Test Co');
    expect(Customer::count())->toBe(2);
});

test('a run cancelled before it starts stops immediately and marks everything Cancelled', function () {
    $run = MigrationRun::create(['status' => MigrationRunStatus::Pending, 'cancelled_at' => now()]);

    (new MigrationRunner($run))->run(['customers', 'delivery_documents'], DuplicateStrategy::UpdateExisting, 'none', $this->admin->id);

    $run->refresh();

    expect($run->status)->toBe(MigrationRunStatus::Cancelled);
    expect($run->finished_at)->not->toBeNull();

    $tables = $run->tables()->get();

    expect($tables)->not->toBeEmpty();
    $tables->each(fn ($table) => expect($table->status)->toBe(MigrationRunStatus::Cancelled));

    expect(Customer::count())->toBe(0);
    expect(Document::count())->toBe(0);
});

test('full wipe creates a fallback admin when none exists', function () {
    User::query()->delete();

    $run = MigrationRun::create(['status' => MigrationRunStatus::Pending]);

    (new MigrationRunner($run))->run(['customers'], DuplicateStrategy::UpdateExisting, 'full_wipe', $this->admin->id);

    $run->refresh();

    $fallbackAdmin = User::where('role', UserRole::Admin)->first();

    expect($fallbackAdmin)->not->toBeNull();
    expect($run->options['fallback_admin']['email'] ?? null)->not->toBeNull();
    expect($run->options['fallback_admin']['password'] ?? null)->not->toBeNull();
});
