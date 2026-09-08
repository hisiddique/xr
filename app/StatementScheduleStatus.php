<?php

namespace App;

enum StatementScheduleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            StatementScheduleStatus::Draft => 'Draft',
            StatementScheduleStatus::Active => 'Active',
            StatementScheduleStatus::Paused => 'Paused',
            StatementScheduleStatus::Completed => 'Completed',
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            StatementScheduleStatus::Draft => 'bg-zinc-50 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-500/10 dark:text-zinc-400',
            StatementScheduleStatus::Active => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
            StatementScheduleStatus::Paused => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400',
            StatementScheduleStatus::Completed => 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400',
        };
    }
}
