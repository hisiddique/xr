<?php

namespace App\Jobs;

use App\MigrationRunStatus;
use App\Models\MigrationRun;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MigrationRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class RunLegacyMigrationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3000;

    public int $tries = 1;

    /**
     * @param  array<int, string>  $selectedGroups
     */
    public function __construct(
        private readonly int $migrationRunId,
        private readonly array $selectedGroups,
        private readonly string $duplicateStrategy,
        private readonly string $clearMode,
        private readonly int $createdByUserId,
    ) {
        $this->onQueue('migrations');
    }

    public function handle(): void
    {
        $run = MigrationRun::findOrFail($this->migrationRunId);

        $this->applyLegacyCredentials($run);

        try {
            (new MigrationRunner($run))->run(
                $this->selectedGroups,
                DuplicateStrategy::from($this->duplicateStrategy),
                $this->clearMode,
                $this->createdByUserId,
            );
        } catch (\Throwable $e) {
            // MigrationRunner already marks the run Failed on per-row/per-mapper
            // failures; this only catches an unexpected escape (e.g. a lost DB
            // connection) so the run record doesn't stay stuck at Running.
            $run->update([
                'status' => MigrationRunStatus::Failed,
                'error' => $e->getMessage(),
                'finished_at' => now(),
            ]);

            throw $e;
        } finally {
            // Credentials were only needed to open the legacy connection for this run;
            // clear them once the run reaches a terminal state, success or failure.
            $run->update(['legacy_credentials' => null]);
        }
    }

    /**
     * If per-run legacy DB credentials were supplied (production hosts may need
     * different credentials than whatever is baked into .env at deploy time),
     * override the 'legacy' connection config for this process before any mapper
     * touches DB::connection('legacy'). Falls back to .env-configured credentials
     * when none were supplied for this run.
     */
    private function applyLegacyCredentials(MigrationRun $run): void
    {
        $credentials = $run->legacy_credentials;

        if (! $credentials) {
            return;
        }

        config(['database.connections.legacy' => array_merge(
            config('database.connections.legacy', []),
            [
                'host' => $credentials['host'],
                'port' => $credentials['port'] ?: 1433,
                'database' => $credentials['database'],
                'username' => $credentials['username'],
                'password' => $credentials['password'],
            ],
        )]);

        DB::purge('legacy');
    }
}
