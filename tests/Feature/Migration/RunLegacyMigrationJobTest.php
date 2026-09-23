<?php

use App\Jobs\RunLegacyMigrationJob;
use App\MigrationRunStatus;
use App\Models\Customer;
use App\Models\Document;
use App\Models\LookupPaymentMethod;
use App\Models\MigrationRun;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Migration\DuplicateStrategy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('a run-ending failure stores a truncated error message, not the raw (potentially huge) exception text', function () {
    $admin = User::factory()->admin()->create();

    $run = MigrationRun::create([
        'status' => MigrationRunStatus::Running,
        'created_by' => $admin->id,
        'legacy_credentials' => [
            'host' => str_repeat('unreachable-host-', 200),
            'port' => 1433,
            'database' => 'nonexistent',
            'username' => 'nobody',
            'password' => 'wrong',
        ],
    ]);

    $job = new RunLegacyMigrationJob(
        $run->id,
        ['customers'],
        DuplicateStrategy::UpdateExisting->value,
        'none',
        $admin->id,
    );

    try {
        $job->handle();
    } catch (Throwable) {
        // The job rethrows after recording the failure — expected here.
    }

    $run->refresh();

    expect($run->status)->toBe(MigrationRunStatus::Failed);
    expect($run->error)->not->toBeNull();
    expect(strlen($run->error))->toBeLessThanOrEqual(2003);
});

/**
 * Seeds enough legacy data (CustSupps, Documents, AccountEntries, AccountPostTypes)
 * to run customers + documents + payments in one job, then confirms reconciliation
 * fires automatically afterward using the run's own already-authenticated legacy
 * connection — this is the whole point of folding it into the job rather than
 * leaving it as a standalone command production can't run (see LegacyPaymentReconciler).
 */
test('reconciliation runs automatically when a run includes both documents and payments, using the same request', function () {
    useLegacyDatabase();
    createLegacyTables(['Units', 'CustSupps', 'Documents', 'Companies', 'CompanySettings']);

    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 701, 'rtype' => 'A', 'name' => 'Auto Reconcile Co', 'add1' => '1 Road', 'town' => 'Town', 'pcode' => 'AA1 1AA', 'email' => 'a@test.com', 'disc' => 0,
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 801, 'rtype' => 'i', 'acctuid' => 701, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 100, 'value' => 100, 'notes' => null, 'ref' => '900001', 'bline' => 0,
    ]);

    Schema::connection('legacy')->create('AccountEntries', function (Blueprint $table): void {
        $table->unsignedBigInteger('uid');
        $table->string('rtype', 1);
        $table->unsignedBigInteger('custid')->nullable();
        $table->decimal('value', 12, 2)->nullable();
        $table->decimal('osvalue', 12, 2)->nullable();
        $table->string('txndate')->nullable();
        $table->string('invno')->nullable();
        $table->unsignedBigInteger('posttype')->nullable();
        $table->string('createddate')->nullable();
        $table->string('modifieddate')->nullable();
    });

    Schema::connection('legacy')->create('AccountPostTypes', function (Blueprint $table): void {
        $table->unsignedBigInteger('uid');
        $table->string('rtype', 1)->nullable();
        $table->string('inout', 3)->nullable();
        $table->integer('entryvalue')->nullable();
    });

    DB::connection('legacy')->table('AccountPostTypes')->insert([
        ['uid' => 85, 'rtype' => 'i', 'inout' => 'OUT', 'entryvalue' => 1],
        ['uid' => 11, 'rtype' => 'A', 'inout' => 'IN', 'entryvalue' => 1],
    ]);

    // The invoice entry itself (osvalue=0 => fully settled) plus a receipt entry.
    DB::connection('legacy')->table('AccountEntries')->insert([
        ['uid' => 901, 'rtype' => 'a', 'custid' => 701, 'value' => 100, 'osvalue' => 0, 'txndate' => '2024-01-01', 'invno' => '900001', 'posttype' => 85],
        ['uid' => 902, 'rtype' => 'a', 'custid' => 701, 'value' => -100, 'osvalue' => 0, 'txndate' => '2024-01-05', 'invno' => 'BACS', 'posttype' => 11],
    ]);

    Schema::connection('legacy')->create('AccountBatchItems', function (Blueprint $table): void {
        $table->unsignedBigInteger('bhead')->nullable();
        $table->unsignedBigInteger('bline')->nullable();
        $table->unsignedBigInteger('cshuid')->nullable();
        $table->string('txnabbr')->nullable();
        $table->string('txnref')->nullable();
        $table->decimal('paymt', 12, 2)->nullable();
        $table->decimal('osvalue', 12, 2)->nullable();
        $table->unsignedBigInteger('posttype')->nullable();
        $table->string('txndate')->nullable();
    });

    // Legacy's own record of this receipt (uid 902) being applied in full to invoice 900001.
    DB::connection('legacy')->table('AccountBatchItems')->insert([
        'bhead' => 1, 'bline' => 1, 'cshuid' => 902, 'txnabbr' => 'INV-', 'txnref' => '900001', 'paymt' => 100, 'posttype' => null, 'txndate' => '2024-01-05',
    ]);

    foreach (['Bank Transfer', 'Cheque', 'Cash', 'Card'] as $name) {
        LookupPaymentMethod::create(['name' => $name]);
    }

    $admin = User::factory()->admin()->create();

    $run = MigrationRun::create(['status' => MigrationRunStatus::Running, 'created_by' => $admin->id]);

    $job = new RunLegacyMigrationJob(
        $run->id,
        ['customers', 'documents', 'payments'],
        DuplicateStrategy::UpdateExisting->value,
        'none',
        $admin->id,
    );

    $job->handle();

    $run->refresh();

    expect($run->status)->toBe(MigrationRunStatus::Completed);
    expect($run->options['reconciliation'] ?? null)->not->toBeNull();
    expect($run->options['reconciliation']['settled'])->toBe(1);
    expect($run->options['reconciliation']['allocation_rows'])->toBe(1);

    $document = Document::where('legacy_uid', 801)->first();
    expect($document->is_settled)->toBeTrue();

    $payment = Payment::where('legacy_uid', 902)->first();
    $allocation = PaymentAllocation::where('document_id', $document->id)->first();

    expect($allocation)->not->toBeNull()
        ->and($allocation->payment_id)->toBe($payment->id)
        ->and((float) $allocation->allocated_amount)->toBe(100.0);
});

/**
 * Seeds a legacy invoice whose legacy osvalue (30) is below what the migrated data
 * implies is outstanding (100, nothing allocated), so the outstanding-settlement
 * step has real work: it should flag the invoice legacy_confirmed_paid, tagged
 * with this run's batch id, and record a summary on the run.
 */
function seedOutstandingMismatchLegacyData(float $osvalue): void
{
    useLegacyDatabase();
    createLegacyTables(['Units', 'CustSupps', 'Documents', 'Companies', 'CompanySettings', 'AccountEntries', 'AccountPostTypes', 'AccountBatchItems']);

    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 710, 'rtype' => 'A', 'name' => 'Mismatch Co', 'add1' => '1 Road', 'town' => 'Town', 'pcode' => 'AA1 1AA', 'email' => 'm@test.com', 'disc' => 0,
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 810, 'rtype' => 'i', 'acctuid' => 710, 'orderno' => null, 'date' => '2024-02-01',
        'goods' => 100, 'value' => 100, 'notes' => null, 'ref' => '910001', 'bline' => 0,
    ]);

    DB::connection('legacy')->table('AccountPostTypes')->insert([
        ['uid' => 85, 'rtype' => 'i', 'inout' => 'OUT', 'entryvalue' => 1],
    ]);

    DB::connection('legacy')->table('AccountEntries')->insert([
        ['uid' => 910, 'rtype' => 'a', 'custid' => 710, 'value' => 100, 'osvalue' => $osvalue, 'txndate' => '2024-02-01', 'invno' => '910001', 'posttype' => 85],
    ]);
}

test('outstanding settlement runs when MORR resolves, flagging the invoice legacy_confirmed_paid', function () {
    seedOutstandingMismatchLegacyData(osvalue: 30);
    Customer::factory()->create(['reference' => 'MORR']);

    $admin = User::factory()->admin()->create();
    $run = MigrationRun::create(['status' => MigrationRunStatus::Running, 'created_by' => $admin->id]);

    (new RunLegacyMigrationJob($run->id, ['customers', 'documents', 'payments'], DuplicateStrategy::UpdateExisting->value, 'none', $admin->id))->handle();

    $run->refresh();
    $document = Document::where('legacy_uid', 810)->first();

    expect($run->options['outstanding_reconciliation']['flagged_from_row_count'] ?? null)->toBe(1)
        ->and($run->options['outstanding_reconciliation_skipped'] ?? null)->toBeNull();

    expect($document->fresh()->legacy_confirmed_paid)->toBeTrue()
        ->and($document->fresh()->legacy_confirmed_paid_batch)->toBe('MIGRATION-'.$run->id)
        ->and(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0);
});

test('outstanding settlement is skipped and the reason recorded when MORR does not resolve', function () {
    seedOutstandingMismatchLegacyData(osvalue: 30);

    $admin = User::factory()->admin()->create();
    $run = MigrationRun::create(['status' => MigrationRunStatus::Running, 'created_by' => $admin->id]);

    (new RunLegacyMigrationJob($run->id, ['customers', 'documents', 'payments'], DuplicateStrategy::UpdateExisting->value, 'none', $admin->id))->handle();

    $run->refresh();

    expect($run->options['outstanding_reconciliation_skipped'] ?? null)->toContain('MORR')
        ->and($run->options['outstanding_reconciliation'] ?? null)->toBeNull()
        ->and(Payment::whereNotNull('reconciliation_batch')->count())->toBe(0);
});

test('outstanding settlement self-heals a deployment missing the legacy_confirmed_paid column', function () {
    seedOutstandingMismatchLegacyData(osvalue: 30);
    Customer::factory()->create(['reference' => 'MORR']);

    Schema::table('documents', function (Blueprint $table) {
        $table->dropColumn(['legacy_confirmed_paid', 'legacy_confirmed_paid_batch']);
    });

    // artisan migrate skips a migration already recorded in the migrations table,
    // regardless of actual schema state — remove the record too, matching a
    // deployment that genuinely never ran this migration.
    DB::table('migrations')->where('migration', '2026_09_23_125112_add_legacy_confirmed_paid_to_documents_table')->delete();

    expect(Schema::hasColumn('documents', 'legacy_confirmed_paid'))->toBeFalse();

    $admin = User::factory()->admin()->create();
    $run = MigrationRun::create(['status' => MigrationRunStatus::Running, 'created_by' => $admin->id]);

    (new RunLegacyMigrationJob($run->id, ['customers', 'documents', 'payments'], DuplicateStrategy::UpdateExisting->value, 'none', $admin->id))->handle();

    $run->refresh();

    expect(Schema::hasColumn('documents', 'legacy_confirmed_paid'))->toBeTrue()
        ->and($run->options['outstanding_reconciliation_error'] ?? null)->toBeNull()
        ->and($run->options['outstanding_reconciliation']['flagged_from_row_count'] ?? null)->toBe(1);

    $document = Document::where('legacy_uid', 810)->first();
    expect($document->fresh()->legacy_confirmed_paid)->toBeTrue();
});

test('reconciliation does not run when only documents (not payments) is selected', function () {
    useLegacyDatabase();
    createLegacyTables(['Units', 'CustSupps', 'Documents', 'Companies', 'CompanySettings']);

    DB::connection('legacy')->table('CustSupps')->insert([
        'uid' => 702, 'rtype' => 'A', 'name' => 'No Reconcile Co', 'add1' => '1 Road', 'town' => 'Town', 'pcode' => 'AA1 1AA', 'email' => 'a@test.com', 'disc' => 0,
    ]);

    DB::connection('legacy')->table('Documents')->insert([
        'uid' => 802, 'rtype' => 'i', 'acctuid' => 702, 'orderno' => null, 'date' => '2024-01-01',
        'goods' => 100, 'value' => 100, 'notes' => null, 'ref' => '900002', 'bline' => 0,
    ]);

    $admin = User::factory()->admin()->create();

    $run = MigrationRun::create(['status' => MigrationRunStatus::Running, 'created_by' => $admin->id]);

    $job = new RunLegacyMigrationJob(
        $run->id,
        ['customers', 'documents'],
        DuplicateStrategy::UpdateExisting->value,
        'none',
        $admin->id,
    );

    $job->handle();

    $run->refresh();

    expect($run->status)->toBe(MigrationRunStatus::Completed);
    expect($run->options['reconciliation'] ?? null)->toBeNull();
});
