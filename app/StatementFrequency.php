<?php

namespace App;

enum StatementFrequency: string
{
    case Monthly = 'monthly';
    case MonthEnd = 'month_end';
    case Weekly = 'weekly';
    case Quarterly = 'quarterly';
    case OneTime = 'one_time';

    public function label(): string
    {
        return match ($this) {
            StatementFrequency::Monthly => 'Monthly (specific day)',
            StatementFrequency::MonthEnd => 'Monthly (end of month)',
            StatementFrequency::Weekly => 'Weekly',
            StatementFrequency::Quarterly => 'Quarterly (every 3 months)',
            StatementFrequency::OneTime => 'One-time',
        };
    }

    /**
     * The schedule_config / cadence columns this frequency requires a value for.
     *
     * @return array<int, string>
     */
    public function requiredFields(): array
    {
        return match ($this) {
            StatementFrequency::Monthly => ['day_of_month', 'run_time'],
            StatementFrequency::MonthEnd => ['run_time'],
            StatementFrequency::Weekly => ['day_of_week', 'run_time'],
            StatementFrequency::Quarterly => ['anchor_date', 'run_time'],
            StatementFrequency::OneTime => ['anchor_date', 'run_time'],
        };
    }

    public function isRecurring(): bool
    {
        return $this !== StatementFrequency::OneTime;
    }
}
