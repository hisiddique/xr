<?php

namespace App\Jobs;

use App\ArchiveRunStatus;
use App\Jobs\Concerns\InteractsWithArchiveConnection;
use App\Models\ArchiveRun;
use App\Services\Archive\ArchiveRunner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RunArchiveJob implements ShouldQueue
{
    use Dispatchable, InteractsWithArchiveConnection, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 7200;

    public int $tries = 1;

    public function __construct(private readonly int $archiveRunId)
    {
        $this->onQueue('archives');
    }

    public function handle(): void
    {
        // TTL > the 7200s job timeout so a crashed job self-heals.
        $lock = Cache::lock(self::LOCK_KEY, 7800);

        if (! $lock->get()) {
            ArchiveRun::find($this->archiveRunId)?->update([
                'status' => ArchiveRunStatus::Failed,
                'error' => 'Another archive or cleanup run is already in progress.',
                'finished_at' => now(),
                'archive_credentials' => null,
            ]);

            return;
        }

        $run = null;

        try {
            $run = ArchiveRun::findOrFail($this->archiveRunId);

            $dateFrom = $run->options['date_from'] ?? null;
            $dateTo = $run->options['date_to'] ?? null;

            if ($dateFrom === null || $dateTo === null) {
                $run->update([
                    'status' => ArchiveRunStatus::Failed,
                    'error' => 'Archive run is missing its date range.',
                    'finished_at' => now(),
                ]);

                return;
            }

            $this->applyArchiveCredentials($run->archive_credentials);

            try {
                app(ArchiveRunner::class, ['run' => $run])->run(
                    $run->options['selected_groups'] ?? [],
                    $dateFrom,
                    $dateTo,
                );
            } catch (\Throwable $e) {
                // ArchiveRunner already marks the run Failed on its own failures; this only
                // catches an unexpected escape so the record doesn't stay stuck Running.
                if (! in_array($run->fresh()?->status, [ArchiveRunStatus::Failed, ArchiveRunStatus::Cancelled, ArchiveRunStatus::Completed], true)) {
                    $run->update([
                        'status' => ArchiveRunStatus::Failed,
                        'error' => Str::limit($e->getMessage(), 2000),
                        'finished_at' => now(),
                    ]);
                }

                throw $e;
            }
        } finally {
            $this->restoreArchiveConnection();
            $run?->update(['archive_credentials' => null]);
            $lock->release();
        }
    }
}
