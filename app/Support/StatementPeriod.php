<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final readonly class StatementPeriod
{
    public function __construct(
        public string $preset,
        public ?string $from,
        public ?string $to,
        public string $label,
    ) {}

    /**
     * @return array<string, string> preset key => human label, for <select> options
     */
    public static function presets(): array
    {
        return [
            'yesterday' => 'Yesterday',
            'this_week' => 'This Week',
            'last_week' => 'Last Week',
            'last_two_weeks' => 'Last Two Weeks',
            'this_month' => 'This Month',
            'last_month' => 'Last Month',
            'three_months_ago' => 'Three Months Ago',
            'six_months_ago' => 'Six Months Ago',
            'this_year' => 'This Year',
            'last_year' => 'Last Year',
            'custom' => 'Custom Date Range',
        ];
    }

    public static function fromPreset(
        string $preset,
        ?string $from = null,
        ?string $to = null,
        ?CarbonInterface $asOf = null,
    ): self {
        $now = $asOf ? Carbon::parse($asOf) : Carbon::now();

        [$resolvedFrom, $resolvedTo] = match ($preset) {
            'yesterday' => [$now->clone()->subDay()->toDateString(), $now->clone()->subDay()->toDateString()],
            'this_week' => [$now->clone()->startOfWeek()->toDateString(), $now->clone()->endOfWeek()->toDateString()],
            'last_week' => [$now->clone()->subWeek()->startOfWeek()->toDateString(), $now->clone()->subWeek()->endOfWeek()->toDateString()],
            'last_two_weeks' => [$now->clone()->subWeeks(2)->startOfWeek()->toDateString(), $now->clone()->endOfWeek()->toDateString()],
            'this_month' => [$now->clone()->startOfMonth()->toDateString(), $now->clone()->endOfMonth()->toDateString()],
            'last_month' => [$now->clone()->subMonthNoOverflow()->startOfMonth()->toDateString(), $now->clone()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            'three_months_ago' => [$now->clone()->subMonthsNoOverflow(3)->startOfMonth()->toDateString(), $now->clone()->subMonthsNoOverflow(3)->endOfMonth()->toDateString()],
            'six_months_ago' => [$now->clone()->subMonthsNoOverflow(6)->startOfMonth()->toDateString(), $now->clone()->subMonthsNoOverflow(6)->endOfMonth()->toDateString()],
            'this_year' => [$now->clone()->startOfYear()->toDateString(), $now->clone()->endOfYear()->toDateString()],
            'last_year' => [$now->clone()->subYear()->startOfYear()->toDateString(), $now->clone()->subYear()->endOfYear()->toDateString()],
            'custom' => [$from ?: null, $to ?: null],
            default => [null, null],
        };

        $label = ($resolvedFrom && $resolvedTo)
            ? Carbon::parse($resolvedFrom)->format('d M Y').' – '.Carbon::parse($resolvedTo)->format('d M Y')
            : 'All dates';

        return new self($preset, $resolvedFrom, $resolvedTo, $label);
    }
}
