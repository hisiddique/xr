<?php

namespace App;

enum DocumentStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Emailed = 'emailed';

    public function label(): string
    {
        return match ($this) {
            DocumentStatus::Active => 'Active',
            DocumentStatus::Converted => 'Converted',
            DocumentStatus::Emailed => 'Emailed',
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            DocumentStatus::Active => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
            DocumentStatus::Converted => 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-400',
            DocumentStatus::Emailed => 'bg-sky-50 text-sky-700 ring-sky-600/20 dark:bg-sky-500/10 dark:text-sky-400',
        };
    }
}
