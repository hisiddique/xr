<?php

namespace App\Services\Archive;

use App\ArchiveRunStatus;
use App\Models\ArchiveRun;
use App\Models\ArchiveRunTable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ArchiveRunner
{
    private const int CHUNK_SIZE = 2000;

    private const int INSERT_BATCH = 1000;

    private const string ARCHIVE = 'archive';

    public function __construct(
        private readonly ArchiveRun $run,
        private readonly ArchiveScopeResolver $scope,
    ) {}

    /**
     * @param  list<string>  $selectedGroups  keys from ArchiveTableRegistry::selectableGroups()
     * @param  string  $dateFrom  Y-m-d, inclusive
     * @param  string  $dateTo  Y-m-d, inclusive
     */
    public function run(array $selectedGroups, string $dateFrom, string $dateTo): void
    {
        $this->run->update([
            'status' => ArchiveRunStatus::Running,
            'started_at' => now(),
        ]);

        $mainConnection = (string) config('database.default');

        $tablesToCopy = $this->scope->tablesFor($selectedGroups);

        /** @var array<string, ArchiveRunTable> $stats */
        $stats = [];

        foreach ($tablesToCopy as $table) {
            $stats[$table] = $this->run->tables()->create([
                'entity' => $table,
                'status' => ArchiveRunStatus::Pending,
                'rows_total' => 0,
                'rows_copied' => 0,
                'rows_deleted' => 0,
                'rows_skipped' => 0,
                'rows_failed' => 0,
            ]);
        }

        $idMap = $this->scope->resolve($selectedGroups, $dateFrom, $dateTo, $mainConnection);
        $currentStat = null;

        try {
            $this->provisionArchiveSchema();

            $this->toggleArchiveForeignKeys(false);

            foreach ($tablesToCopy as $table) {
                $currentStat = $stats[$table];
                $currentStat->update(['status' => ArchiveRunStatus::Running]);

                $ids = $idMap[$table] ?? [];

                $currentStat->update(['rows_total' => count($ids)]);

                $chunkIndex = 0;

                foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunkIds) {
                    $rows = array_map(
                        static fn (object $row): array => (array) $row,
                        DB::connection($mainConnection)->table($table)->whereIn('id', $chunkIds)->get()->all(),
                    );

                    if ($rows !== []) {
                        foreach (array_chunk($rows, self::INSERT_BATCH) as $insertBatch) {
                            DB::connection(self::ARCHIVE)->table($table)->insertOrIgnore($insertBatch);
                        }
                    }

                    $currentStat->rows_copied += count($chunkIds);

                    if (++$chunkIndex % 10 === 0) {
                        $currentStat->save();
                    }

                    if ($this->isCancelled()) {
                        $currentStat->update(['status' => ArchiveRunStatus::Cancelled]);

                        $this->run->update([
                            'status' => ArchiveRunStatus::Cancelled,
                            'finished_at' => now(),
                        ]);

                        return;
                    }
                }

                $currentStat->save();
                $currentStat->update(['status' => ArchiveRunStatus::Completed]);
            }
        } catch (\Throwable $e) {
            $currentStat?->update([
                'status' => ArchiveRunStatus::Failed,
                'error' => Str::limit($e->getMessage(), 2000),
            ]);

            $this->run->update([
                'status' => ArchiveRunStatus::Failed,
                'error' => Str::limit($e->getMessage(), 2000),
                'finished_at' => now(),
            ]);

            throw $e;
        } finally {
            $this->toggleArchiveForeignKeys(true);
        }

        $this->run->update([
            'status' => ArchiveRunStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    private function provisionArchiveSchema(): void
    {
        // migrate is idempotent — always run it so schema additions reach the archive DB too.
        $exitCode = Artisan::call('migrate', [
            '--database' => self::ARCHIVE,
            '--force' => true,
        ]);

        if ($exitCode !== 0) {
            throw new \RuntimeException('Failed to provision the archive database schema (migrate exit code '.$exitCode.').');
        }
    }

    private function toggleArchiveForeignKeys(bool $enabled): void
    {
        $driver = DB::connection(self::ARCHIVE)->getDriverName();

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return; // sqlite (tests) manages this via the connection's foreign_key_constraints flag
        }

        DB::connection(self::ARCHIVE)->statement('SET FOREIGN_KEY_CHECKS = '.($enabled ? '1' : '0'));
    }

    private function isCancelled(): bool
    {
        return $this->run->fresh()?->cancelled_at !== null;
    }
}
