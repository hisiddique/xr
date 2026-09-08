<?php

namespace App;

enum StatementRunItemStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            StatementRunItemStatus::Pending => 'Pending',
            StatementRunItemStatus::Sent => 'Sent',
            StatementRunItemStatus::Failed => 'Failed',
            StatementRunItemStatus::Skipped => 'Skipped',
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            StatementRunItemStatus::Pending => 'bg-zinc-50 text-zinc-600 ring-zinc-500/20 dark:bg-zinc-500/10 dark:text-zinc-400',
            StatementRunItemStatus::Sent => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
            StatementRunItemStatus::Failed => 'bg-rose-50 text-rose-700 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400',
            StatementRunItemStatus::Skipped => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400',
        };
    }
}
