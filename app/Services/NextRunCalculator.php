<?php

namespace App\Services;

use App\Models\StatementSchedule;
use App\StatementFrequency;
use Carbon\Carbon;
use Carbon\CarbonInterface;

class NextRunCalculator
{
    /**
     * Next fire time for a schedule, strictly after $after, returned as UTC Carbon.
     *
     * Returns null when a required field for the frequency is missing, or when a
     * one-time schedule has already fired.
     */
    public function nextRunAt(StatementSchedule $schedule, ?CarbonInterface $after = null): ?CarbonInterface
    {
        $tz = config('app.timezone');
        $after = $after ? Carbon::parse($after)->setTimezone($tz) : Carbon::now($tz);

        [$hour, $minute] = $this->parseRunTime($schedule->run_time);

        $occurrence = match ($schedule->frequency) {
            StatementFrequency::Monthly => $this->monthly($schedule, $after, $hour, $minute),
            StatementFrequency::MonthEnd => $this->monthEnd($after, $hour, $minute),
            StatementFrequency::Weekly => $this->weekly($schedule, $after, $hour, $minute),
            StatementFrequency::Quarterly => $this->quarterly($schedule, $after, $tz, $hour, $minute),
            StatementFrequency::OneTime => $this->oneTime($schedule, $after, $tz, $hour, $minute),
        };

        return $occurrence?->setTimezone('UTC');
    }

    /**
     * @return array{0: int, 1: int} hour, minute parsed from "HH:MM" or "HH:MM:SS"
     */
    private function parseRunTime(?string $runTime): array
    {
        $parts = array_pad(explode(':', $runTime ?: '00:00'), 2, '0');

        return [(int) $parts[0], (int) $parts[1]];
    }

    /**
     * The configured day_of_month (1..28) at run_time, this month or the next.
     */
    private function monthly(StatementSchedule $schedule, CarbonInterface $after, int $hour, int $minute): ?CarbonInterface
    {
        if ($schedule->day_of_month === null) {
            return null;
        }

        $candidate = $after->copy()->startOfMonth()->day($schedule->day_of_month)->setTime($hour, $minute, 0);

        if ($candidate->lessThanOrEqualTo($after)) {
            $candidate = $after->copy()->startOfMonth()->addMonthNoOverflow()
                ->day($schedule->day_of_month)->setTime($hour, $minute, 0);
        }

        return $candidate;
    }

    /**
     * Last calendar day of the month at run_time, this month or the next.
     */
    private function monthEnd(CarbonInterface $after, int $hour, int $minute): CarbonInterface
    {
        $candidate = $after->copy()->endOfMonth()->setTime($hour, $minute, 0);

        if ($candidate->lessThanOrEqualTo($after)) {
            $candidate = $after->copy()->addMonthNoOverflow()->endOfMonth()->setTime($hour, $minute, 0);
        }

        return $candidate;
    }

    /**
     * Next date whose ISO weekday matches day_of_week (1=Mon..7=Sun) at run_time.
     */
    private function weekly(StatementSchedule $schedule, CarbonInterface $after, int $hour, int $minute): ?CarbonInterface
    {
        if ($schedule->day_of_week === null) {
            return null;
        }

        $candidate = $after->copy()->setTime($hour, $minute, 0);

        while ($candidate->dayOfWeekIso !== $schedule->day_of_week) {
            $candidate->addDay();
        }

        if ($candidate->lessThanOrEqualTo($after)) {
            $candidate->addDays(7);
        }

        return $candidate;
    }

    /**
     * Anchored every 3 months from anchor_date at run_time.
     */
    private function quarterly(StatementSchedule $schedule, CarbonInterface $after, string $tz, int $hour, int $minute): ?CarbonInterface
    {
        if ($schedule->anchor_date === null) {
            return null;
        }

        $candidate = Carbon::parse($schedule->anchor_date->format('Y-m-d'), $tz)->setTime($hour, $minute, 0);

        while ($candidate->lessThanOrEqualTo($after)) {
            $candidate->addMonthsNoOverflow(3);
        }

        return $candidate;
    }

    /**
     * Fires once on anchor_date at run_time; null once that moment has passed.
     */
    private function oneTime(StatementSchedule $schedule, CarbonInterface $after, string $tz, int $hour, int $minute): ?CarbonInterface
    {
        if ($schedule->anchor_date === null) {
            return null;
        }

        $candidate = Carbon::parse($schedule->anchor_date->format('Y-m-d'), $tz)->setTime($hour, $minute, 0);

        return $candidate->greaterThan($after) ? $candidate : null;
    }
}
