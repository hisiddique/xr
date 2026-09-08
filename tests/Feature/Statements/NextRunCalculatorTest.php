<?php

use App\Models\StatementSchedule;
use App\Services\NextRunCalculator;
use App\StatementFrequency;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function calc(): NextRunCalculator
{
    return app(NextRunCalculator::class);
}

test('monthly picks the configured day at run time, rolling to next month when passed', function () {
    $schedule = StatementSchedule::factory()->make([
        'frequency' => StatementFrequency::Monthly,
        'day_of_month' => 10,
        'run_time' => '09:00',
    ]);

    // before the 10th
    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-03-05 12:00', 'UTC'))->toDateTimeString())
        ->toBe('2026-03-10 09:00:00');

    // exactly on it but after run time -> next month
    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-03-10 09:00', 'UTC'))->toDateTimeString())
        ->toBe('2026-04-10 09:00:00');
});

test('month_end resolves the last calendar day, correct in February', function () {
    $schedule = StatementSchedule::factory()->make([
        'frequency' => StatementFrequency::MonthEnd,
        'day_of_month' => null,
        'run_time' => '17:00',
    ]);

    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-02-15 00:00', 'UTC'))->toDateTimeString())
        ->toBe('2026-02-28 17:00:00');

    expect(calc()->nextRunAt($schedule, Carbon::parse('2028-02-15 00:00', 'UTC'))->toDateTimeString())
        ->toBe('2028-02-29 17:00:00'); // leap year
});

test('weekly finds the next matching ISO weekday', function () {
    $schedule = StatementSchedule::factory()->make([
        'frequency' => StatementFrequency::Weekly,
        'day_of_month' => null,
        'day_of_week' => 3, // Wednesday
        'run_time' => '08:30',
    ]);

    // Mon 2026-03-02 -> next Wed 2026-03-04
    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-03-02 10:00', 'UTC'))->toDateTimeString())
        ->toBe('2026-03-04 08:30:00');

    // On the Wednesday after run time -> following Wednesday
    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-03-04 09:00', 'UTC'))->toDateTimeString())
        ->toBe('2026-03-11 08:30:00');
});

test('quarterly steps three months from the anchor until it is in the future', function () {
    $schedule = StatementSchedule::factory()->make([
        'frequency' => StatementFrequency::Quarterly,
        'day_of_month' => null,
        'anchor_date' => '2026-01-15',
        'run_time' => '06:00',
    ]);

    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-01-01', 'UTC'))->toDateTimeString())
        ->toBe('2026-01-15 06:00:00');

    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-05-01', 'UTC'))->toDateTimeString())
        ->toBe('2026-07-15 06:00:00');
});

test('one_time fires once and then returns null', function () {
    $schedule = StatementSchedule::factory()->make([
        'frequency' => StatementFrequency::OneTime,
        'day_of_month' => null,
        'anchor_date' => '2026-06-20',
        'run_time' => '12:00',
    ]);

    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-06-01', 'UTC'))->toDateTimeString())
        ->toBe('2026-06-20 12:00:00');

    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-06-20 12:00', 'UTC')))->toBeNull();
    expect(calc()->nextRunAt($schedule, Carbon::parse('2026-07-01', 'UTC')))->toBeNull();
});

test('the occurrence is returned as UTC using the application timezone', function () {
    // config('app.timezone') is UTC, so run_time is the wall time and the result is UTC.
    $schedule = StatementSchedule::factory()->make([
        'frequency' => StatementFrequency::Monthly,
        'day_of_month' => 1,
        'run_time' => '09:00',
    ]);

    $next = calc()->nextRunAt($schedule, Carbon::parse('2026-06-15 00:00', 'UTC'));

    expect($next->toDateTimeString())->toBe('2026-07-01 09:00:00')
        ->and($next->timezoneName)->toBe('UTC');
});

test('missing required fields yield null instead of throwing', function () {
    $monthly = StatementSchedule::factory()->make([
        'frequency' => StatementFrequency::Monthly,
        'day_of_month' => null,
    ]);
    expect(calc()->nextRunAt($monthly, Carbon::parse('2026-03-01', 'UTC')))->toBeNull();

    $weekly = StatementSchedule::factory()->make([
        'frequency' => StatementFrequency::Weekly,
        'day_of_month' => null,
        'day_of_week' => null,
    ]);
    expect(calc()->nextRunAt($weekly, Carbon::parse('2026-03-01', 'UTC')))->toBeNull();
});
