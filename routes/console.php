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
