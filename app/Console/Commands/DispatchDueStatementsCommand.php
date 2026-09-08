<?php

namespace App\Console\Commands;

use App\Jobs\ProcessStatementDispatchRunJob;
use App\Models\StatementSchedule;
use App\Services\NextRunCalculator;
use App\Services\StatementDispatchService;
use App\StatementScheduleStatus;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DispatchDueStatementsCommand extends Command
{
    protected $signature = 'statements:dispatch';

    protected $description = 'Queue a dispatch run for every statement schedule that is due.';

    public function handle(StatementDispatchService $service, NextRunCalculator $calculator): int
    {
        $due = StatementSchedule::query()->due()->get();

        foreach ($due as $schedule) {
            $scheduledFor = $schedule->next_run_at?->copy() ?? now();

            try {
                DB::transaction(function () use ($service, $calculator, $schedule, $scheduledFor) {
                    $run = $service->createRunForSchedule($schedule, $scheduledFor);

                    $isRecurring = $schedule->frequency->isRecurring();

                    $schedule->update([
                        'last_run_at' => now(),
                        // A one-time schedule has fired for good — retire it so it drops out
                        // of the "Active" list and the due query.
                        'next_run_at' => $isRecurring ? $calculator->nextRunAt($schedule, now()) : null,
                        'status' => $isRecurring ? $schedule->status : StatementScheduleStatus::Completed,
                    ]);

                    ProcessStatementDispatchRunJob::dispatch($run->id)->afterCommit();
                });
            } catch (QueryException $e) {
                $this->warn("Schedule {$schedule->id} occurrence already claimed; skipping.");

                continue;
            }

            $this->info("Queued run for schedule {$schedule->id} ({$schedule->name}).");
        }

        return self::SUCCESS;
    }
}
