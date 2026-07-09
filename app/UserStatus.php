<?php

namespace App;

enum UserStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Migrated = 'migrated';

    public function label(): string
    {
        return match ($this) {
            UserStatus::Active => 'Active',
            UserStatus::Inactive => 'Inactive',
            UserStatus::Migrated => 'Migrated (no login)',
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            UserStatus::Active => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
            UserStatus::Inactive => 'bg-zinc-50 text-zinc-700 ring-zinc-600/20 dark:bg-zinc-500/10 dark:text-zinc-400',
            UserStatus::Migrated => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400',
        };
    }

    public function canLogIn(): bool
    {
        return $this === UserStatus::Active;
    }
}
