<?php

use App\ArchiveRunMode;
use App\ArchiveRunStatus;
use App\Models\ArchiveRun;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentItem;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Services\Archive\ArchiveRunner;
use App\Services\Archive\CleanupRunner;

beforeEach(fn () => useArchiveDatabase());

/**
 * @param  list<string>  $groups
 */
function completedSourceArchive(array $groups, string $from, string $to): ArchiveRun
{
    $source = ArchiveRun::create([
        'status' => ArchiveRunStatus::Pending,
        'mode' => ArchiveRunMode::Archive,
        'options' => ['selected_groups' => $groups, 'date_from' => $from, 'date_to' => $to],
    ]);

    app(ArchiveRunner::class, ['run' => $source])->run($groups, $from, $to);

    expect($source->fresh()->status)->toBe(ArchiveRunStatus::Completed);

    return $source->fresh();
}

/**
 * @param  list<string>  $groups
 */
function cleanupRunFor(array $groups, string $from, string $to): ArchiveRun
{
    return ArchiveRun::create([
        'status' => ArchiveRunStatus::Pending,
        'mode' => ArchiveRunMode::Cleanup,
        'options' => ['selected_groups' => $groups, 'date_from' => $from, 'date_to' => $to],
    ]);
}

it('hard-deletes archived rows from the main DB in FK-safe order', function () {
    $customer = Customer::factory()->create();
    backdate('customers', $customer->id, '2020-04-01 12:00:00');

    $document = Document::factory()->deliveryNote()->for($customer)->create();
    backdate('documents', $document->id, '2020-06-01 12:00:00');
    DocumentItem::factory()->count(2)->for($document)->create();

    $source = completedSourceArchive(['customers', 'documents'], '2020-01-01', '2020-12-31');

    $cleanup = cleanupRunFor(['customers', 'documents'], '2020-01-01', '2020-12-31');

    app(CleanupRunner::class, ['run' => $cleanup])->run($source);

    expect(Document::withTrashed()->count())->toBe(0);
    expect(DocumentItem::withTrashed()->count())->toBe(0);
    expect(Customer::withTrashed()->count())->toBe(0);
    expect($cleanup->fresh()->status)->toBe(ArchiveRunStatus::Completed);

    $documentsStat = $cleanup->tables()->where('entity', 'documents')->first();
    expect($documentsStat->rows_deleted)->toBe(1);
});

it('keeps a row still referenced by a live out-of-scope record', function () {
    $customer = Customer::factory()->create();
    backdate('customers', $customer->id, '2020-02-01 12:00:00');

    $invoice = Document::factory()->invoice()->for($customer)->create();
    backdate('documents', $invoice->id, '2020-05-01 12:00:00');

    $latePayment = Payment::factory()->for($customer)->create();
    backdate('payments', $latePayment->id, '2024-06-01 12:00:00');
    PaymentAllocation::factory()->create([
        'payment_id' => $latePayment->id,
        'document_id' => $invoice->id,
    ]);

    $source = completedSourceArchive(['documents'], '2020-01-01', '2020-12-31');

    $cleanup = cleanupRunFor(['documents'], '2020-01-01', '2020-12-31');

    app(CleanupRunner::class, ['run' => $cleanup])->run($source);

    expect(Document::withTrashed()->whereKey($invoice->id)->exists())->toBeTrue();

    $documentsStat = $cleanup->tables()->where('entity', 'documents')->first();
    expect($documentsStat->rows_skipped)->toBeGreaterThanOrEqual(1);
    expect($documentsStat->error)->toContain('kept');
    expect($documentsStat->error)->toContain('referenced');
    expect($cleanup->fresh()->status)->toBe(ArchiveRunStatus::Completed);
});

it('refuses when the source archive run is not Completed', function () {
    $customer = Customer::factory()->create();
    $document = Document::factory()->deliveryNote()->for($customer)->create();
    backdate('documents', $document->id, '2020-06-01 12:00:00');

    $sourceFailed = ArchiveRun::create([
        'status' => ArchiveRunStatus::Failed,
        'mode' => ArchiveRunMode::Archive,
        'options' => [
            'selected_groups' => ['documents'],
            'date_from' => '2020-01-01',
            'date_to' => '2020-12-31',
        ],
    ]);

    $cleanup = cleanupRunFor(['documents'], '2020-01-01', '2020-12-31');

    app(CleanupRunner::class, ['run' => $cleanup])->run($sourceFailed);

    expect($cleanup->fresh()->status)->toBe(ArchiveRunStatus::Failed);
    expect($cleanup->fresh()->error)->not->toBeEmpty();
    expect(Document::withTrashed()->whereKey($document->id)->exists())->toBeTrue();
});

it('skips deletes for a table whose execution-time archive parity mismatches', function () {
    $customer = Customer::factory()->create();
    backdate('customers', $customer->id, '2020-03-01 12:00:00');

    $document = Document::factory()->deliveryNote()->for($customer)->create();
    backdate('documents', $document->id, '2020-07-01 12:00:00');

    $source = completedSourceArchive(['documents'], '2020-01-01', '2020-12-31');

    archiveDb()->table('documents')->where('id', $document->id)->delete();

    $cleanup = cleanupRunFor(['documents'], '2020-01-01', '2020-12-31');

    app(CleanupRunner::class, ['run' => $cleanup])->run($source);

    $documentsStat = $cleanup->tables()->where('entity', 'documents')->first();
    expect($documentsStat->status)->toBe(ArchiveRunStatus::Failed);
    expect($documentsStat->error)->toContain('Archive holds only');
    expect(Document::withTrashed()->whereKey($document->id)->exists())->toBeTrue();
    expect($cleanup->fresh()->status)->toBe(ArchiveRunStatus::Failed);
});

it('keeps an invoice still referenced by a live self-referential credit note', function () {
    $customer = Customer::factory()->create();
    backdate('customers', $customer->id, '2020-02-01 12:00:00');

    $invoice = Document::factory()->invoice()->for($customer)->create();
    backdate('documents', $invoice->id, '2020-05-01 12:00:00');

    $liveCreditNote = Document::factory()->creditNote()->for($customer)->create([
        'credited_invoice_id' => $invoice->id,
    ]);
    backdate('documents', $liveCreditNote->id, '2024-05-01 12:00:00');

    $source = completedSourceArchive(['documents'], '2020-01-01', '2020-12-31');

    $cleanup = cleanupRunFor(['documents'], '2020-01-01', '2020-12-31');

    app(CleanupRunner::class, ['run' => $cleanup])->run($source);

    expect(Document::withTrashed()->whereKey($invoice->id)->exists())->toBeTrue();

    $documentsStat = $cleanup->tables()->where('entity', 'documents')->first();
    expect($documentsStat->rows_skipped)->toBeGreaterThanOrEqual(1);
    expect($documentsStat->error)->toContain('kept');
    expect($documentsStat->error)->toContain('referenced');
    expect($cleanup->fresh()->status)->toBe(ArchiveRunStatus::Completed);
});
