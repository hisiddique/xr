<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// This hosting environment has no persistent queue:work supervisor, only cron.
// A single `* * * * * php artisan schedule:run` crontab entry is a deployment
// prerequisite for this tick (and thus the migrations queue) to ever drain.
// withoutOverlapping()'s lock defaults to a 24-hour TTL — if this process is ever
// killed abnormally (e.g. Ctrl+C mid-run during manual testing) without releasing
// it, the migrations queue would silently stop draining for up to a day. Bound it
// to 10 minutes instead, comfortably above a single tick's own --max-time budget.
Schedule::command('queue:work --queue=migrations --stop-when-empty --max-time=50 --tries=1')
    ->everyMinute()
    ->withoutOverlapping(10);
