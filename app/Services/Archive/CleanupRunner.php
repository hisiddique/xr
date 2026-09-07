<?php

namespace App\Services\Archive;

use App\ArchiveRunStatus;
use App\Models\ArchiveRun;
use App\Models\ArchiveRunTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CleanupRunner
{
    private const int CHUNK_SIZE = 2000;

    private const string ARCHIVE = 'archive';

    public function __construct(
        private readonly ArchiveRun $run,
        private readonly ArchiveScopeResolver $scope,
        private readonly ArchiveTableRegistry $registry,
    ) {}

    public function run(ArchiveRun $sourceRun): void
    {
        $this->run->update([
            'status' => ArchiveRunStatus::Running,
            'started_at' => now(),
        ]);

        if ($sourceRun->status !== ArchiveRunStatus::Completed) {
            $this->run->update([
                'status' => ArchiveRunStatus::Failed,
                'error' => 'The selected archive run has not completed, so there is nothing verified to clean up.',
                'finished_at' => now(),
            ]);

            return;
        }

        $selectedGroups = $sourceRun->options['selected_groups'] ?? [];
        $dateFrom = $sourceRun->options['date_from'] ?? null;
        $dateTo = $sourceRun->options['date_to'] ?? null;

        if ($selectedGroups === [] || $dateFrom === null || $dateTo === null) {
            $this->run->update([
                'status' => ArchiveRunStatus::Failed,
                'error' => 'Archive run is missing its scope details.',
                'finished_at' => now(),
            ]);

            return;
        }

        $mainConnection = (string) config('database.default');

        $idMap = $this->scope->resolve($selectedGroups, $dateFrom, $dateTo, $mainConnection);

        $tables = array_values(array_filter(
            $this->registry->deleteOrder(),
            static fn (string $table): bool => isset($idMap[$table]) && $idMap[$table] !== [],
        ));

        /** @var array<string, ArchiveRunTable> $stats */
        $stats = [];

        foreach ($tables as $table) {
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

        $currentStat = null;

        /** @var array<string, list<int>> $deletedIds */
        $deletedIds = [];

        try {
            foreach ($tables as $table) {
                $currentStat = $stats[$table];
                $currentStat->update(['status' => ArchiveRunStatus::Running]);

                $ids = $idMap[$table];
                $currentStat->update(['rows_total' => count($ids)]);

                $archived = $this->countArchived($table, $ids);

                if ($archived < count($ids)) {
                    $currentStat->update([
                        'status' => ArchiveRunStatus::Failed,
                        'error' => sprintf(
                            'Archive holds only %d of %d rows for this table; deletes skipped to avoid data loss.',
                            $archived,
                            count($ids),
                        ),
                        'rows_skipped' => count($ids),
                    ]);

                    continue;
                }

                $inboundReferences = $this->registry->inboundReferences($table);

                $chunkIndex = 0;

                foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunkIds) {
                    $blocked = [];

                    foreach ($inboundReferences as $edge) {
                        $deletedChildSet = array_flip($deletedIds[$edge['table']] ?? []);

                        $referencing = DB::connection($mainConnection)->table($edge['table'])
                            ->whereIn($edge['column'], $chunkIds)
                            ->get(['id', $edge['column']]);

                        foreach ($referencing as $referencingRow) {
                            if (! isset($deletedChildSet[$referencingRow->id])) {
                                $blocked[] = (int) $referencingRow->{$edge['column']};
                            }
                        }
                    }

                    $blocked = array_values(array_unique($blocked));

                    $deletable = array_values(array_diff($chunkIds, $blocked));

                    // Raw query-builder delete() is a hard delete: it bypasses the model SoftDeletes scope, which is required here.
                    DB::connection($mainConnection)->transaction(function () use ($mainConnection, $table, $deletable): void {
                        DB::connection($mainConnection)->table($table)->whereIn('id', $deletable)->delete();
                    });

                    $deletedIds[$table] = array_merge($deletedIds[$table] ?? [], $deletable);

                    $currentStat->rows_deleted += count($deletable);
                    $currentStat->rows_skipped += count($blocked);

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

                if ($currentStat->rows_skipped > 0) {
                    $currentStat->update([
                        'error' => sprintf(
                            '%d row(s) kept: still referenced by live records.',
                            $currentStat->rows_skipped,
                        ),
                    ]);
                }

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
        }

        $hadParityFailure = $this->run->tables()->where('status', ArchiveRunStatus::Failed)->exists();

        $this->run->update([
            'status' => $hadParityFailure ? ArchiveRunStatus::Failed : ArchiveRunStatus::Completed,
            'error' => $hadParityFailure
                ? 'One or more tables were skipped because the archive did not fully match the main database — see per-table details.'
                : null,
            'finished_at' => now(),
        ]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function countArchived(string $table, array $ids): int
    {
        $total = 0;

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunkIds) {
            $total += DB::connection(self::ARCHIVE)->table($table)->whereIn('id', $chunkIds)->count();
        }

        return $total;
    }

    private function isCancelled(): bool
    {
        return $this->run->fresh()?->cancelled_at !== null;
    }
}
