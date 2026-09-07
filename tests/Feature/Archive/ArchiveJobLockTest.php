<?php

use App\ArchiveRunMode;
use App\ArchiveRunStatus;
use App\Jobs\RunArchiveCleanupJob;
use App\Jobs\RunArchiveJob;
use App\Models\ArchiveRun;
use App\Models\Customer;
use App\Models\Document;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

beforeEach(fn () => useArchiveDatabase());

it('aborts a second archive job while the lock is held', function () {
    $heldLock = Cache::lock('archive:run', 10);
    expect($heldLock->get())->toBeTrue();

    $run = ArchiveRun::create([
        'status' => ArchiveRunStatus::Pending,
        'mode' => ArchiveRunMode::Archive,
        'options' => [
            'selected_groups' => ['documents'],
            'date_from' => '2020-01-01',
            'date_to' => '2020-12-31',
        ],
    ]);

    (new RunArchiveJob($run->id))->handle();

    $run->refresh();

    expect($run->status)->toBe(ArchiveRunStatus::Failed);
    expect($run->error)->toContain('in progress');
    expect($run->tables()->count())->toBe(0);
    expect(archiveDb()->table('documents')->count())->toBe(0);

    $heldLock->release();
});

it('aborts a second cleanup job while the lock is held', function () {
    $heldLock = Cache::lock('archive:run', 10);
    expect($heldLock->get())->toBeTrue();

    $source = ArchiveRun::create([
        'status' => ArchiveRunStatus::Completed,
        'mode' => ArchiveRunMode::Archive,
        'options' => [
            'selected_groups' => ['documents'],
            'date_from' => '2020-01-01',
            'date_to' => '2020-12-31',
        ],
    ]);

    $run = ArchiveRun::create([
        'status' => ArchiveRunStatus::Pending,
        'mode' => ArchiveRunMode::Cleanup,
        'options' => ['source_archive_run_id' => $source->id],
    ]);

    (new RunArchiveCleanupJob($run->id))->handle();

    $run->refresh();

    expect($run->status)->toBe(ArchiveRunStatus::Failed);
    expect($run->error)->toContain('in progress');
    expect($run->tables()->count())->toBe(0);

    $heldLock->release();
});

it('releases the lock after a successful archive job', function () {
    $customer = Customer::factory()->create();
    $document = Document::factory()->deliveryNote()->for($customer)->create();
    DB::table('documents')->where('id', $document->id)->update(['created_at' => '2020-06-01 12:00:00']);

    $run = ArchiveRun::create([
        'status' => ArchiveRunStatus::Pending,
        'mode' => ArchiveRunMode::Archive,
        'options' => [
            'selected_groups' => ['documents'],
            'date_from' => '2020-01-01',
            'date_to' => '2020-12-31',
        ],
    ]);

    (new RunArchiveJob($run->id))->handle();

    expect($run->fresh()->status)->toBe(ArchiveRunStatus::Completed);
    expect($run->tables()->where('entity', 'documents')->first()->rows_copied)->toBe(1);

    $lock = Cache::lock('archive:run', 1);
    expect($lock->get())->toBeTrue();
    $lock->release();
});
