<?php

use App\Jobs\RunLegacyMigrationJob;
use App\MigrationRunStatus;
use App\Models\MigrationRun;
use App\Models\User;
use App\Services\Migration\DuplicateStrategy;

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
