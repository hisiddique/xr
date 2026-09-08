<?php

namespace App;

enum StatementRunStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case CompletedWithErrors = 'completed_with_errors';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            StatementRunStatus::Queued => 'Queued',
            StatementRunStatus::Processing => 'Processing',
            StatementRunStatus::Completed => 'Completed',
            StatementRunStatus::CompletedWithErrors => 'Completed with errors',
            StatementRunStatus::Failed => 'Failed',
        };
    }

    public function isTerminal(): bool
    {
        return match ($this) {
            StatementRunStatus::Queued, StatementRunStatus::Processing => false,
            default => true,
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            StatementRunStatus::Queued => 'bg-zinc-50 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-500/10 dark:text-zinc-400',
            StatementRunStatus::Processing => 'bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400',
            StatementRunStatus::Completed => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
            StatementRunStatus::CompletedWithErrors => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400',
            StatementRunStatus::Failed => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400',
        };
    }
}
