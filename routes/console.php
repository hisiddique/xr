<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requires `* * * * * php artisan schedule:run` in crontab (no persistent worker here).
// Overlap lock set to 5h to outlast the job's own 4h timeout.
Schedule::command('queue:work --queue=migrations --stop-when-empty --max-time=50 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(300);

// Requires `* * * * * php artisan schedule:run` in crontab (no persistent worker here).
// Overlap lock (2100s) sized above this job's own 1800s timeout so a second
// `queue:work` doesn't start while an export job may still be running.
Schedule::command('queue:work --queue=exports --stop-when-empty --max-time=50 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(2100);

// Requires `* * * * * php artisan schedule:run` in crontab (no persistent worker here).
Schedule::command('queue:work --queue=emails --stop-when-empty --max-time=50 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(300);

// Requires `* * * * * php artisan schedule:run` in crontab (no persistent worker here).
// Overlap lock (150 min) sized above this job's own 7200s (2h) timeout so a second
// `queue:work` doesn't start while an archive or cleanup job may still be running.
Schedule::command('queue:work --queue=archives --stop-when-empty --max-time=50 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(150);

// Requires `* * * * * php artisan schedule:run` in crontab (no persistent worker here).
// Overlap lock (15 min) sized above ProcessStatementDispatchRunJob's own 600s timeout.
Schedule::command('queue:work --queue=statements --stop-when-empty --max-time=50 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(15);

// Claims any statement schedule whose next_run_at is due and queues one dispatch run each.
// Kept cheap (no per-recipient work) so the lock can be short.
Schedule::command('statements:dispatch')
    ->everyMinute()
    ->withoutOverlapping(5);
