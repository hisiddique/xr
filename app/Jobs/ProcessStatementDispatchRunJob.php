<?php

namespace App\Jobs;

use App\Mail\StatementDispatchSummaryMail;
use App\Models\StatementDispatchRun;
use App\Services\StatementDispatchService;
use App\StatementRunItemStatus;
use App\StatementRunStatus;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class ProcessStatementDispatchRunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(public int $runId)
    {
        $this->onQueue('statements');
    }

    public function handle(StatementDispatchService $service): void
    {
        $run = StatementDispatchRun::find($this->runId);

        if ($run === null || $run->status->isTerminal()) {
            return;
        }

        try {
            if ($run->status === StatementRunStatus::Queued) {
                $run->update(['status' => StatementRunStatus::Processing, 'started_at' => now()]);
                $service->materializeItems($run);
            }

            if ($run->total_count === 0 && $run->items()->doesntExist()) {
                $service->finalizeRun($run);
                $this->notify($run->refresh());

                return;
            }

            $chunk = max(1, (int) config('statements.dispatch_chunk', 20));
            $throttle = max(0, (int) config('statements.mail_throttle_us', 0));

            $pending = $run->items()
                ->where('status', StatementRunItemStatus::Pending)
                ->limit($chunk)
                ->get();

            foreach ($pending as $item) {
                try {
                    $service->processItem($item, $run);
                } catch (\Throwable $e) {
                    $item->update([
                        'status' => StatementRunItemStatus::Failed,
                        'error_message' => Str::limit($e->getMessage(), 2000),
                    ]);
                }

                if ($throttle > 0) {
                    usleep($throttle);
                }
            }

            $run->update([
                'sent_count' => $run->items()->where('status', StatementRunItemStatus::Sent)->count(),
                'failed_count' => $run->items()->where('status', StatementRunItemStatus::Failed)->count(),
                'skipped_count' => $run->items()->where('status', StatementRunItemStatus::Skipped)->count(),
            ]);

            if ($run->items()->where('status', StatementRunItemStatus::Pending)->exists()) {
                self::dispatch($run->id);

                return;
            }

            $service->finalizeRun($run);
            $this->notify($run->refresh());
        } catch (\Throwable $e) {
            $run->update([
                'status' => StatementRunStatus::Failed,
                'error' => Str::limit($e->getMessage(), 2000),
                'finished_at' => now(),
            ]);
            $this->notify($run->refresh());

            throw $e;
        }
    }

    private function notify(StatementDispatchRun $run): void
    {
        $schedule = $run->schedule;

        if ($schedule === null || ! $schedule->notify_enabled) {
            return;
        }

        $recipients = array_values(array_filter($schedule->notify_emails ?? []));

        if ($recipients === []) {
            return;
        }

        Mail::to($recipients)->send(new StatementDispatchSummaryMail($run->id));
    }
}
