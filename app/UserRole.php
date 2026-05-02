<?php

namespace App;

enum UserRole: string
{
    case Admin = 'admin';
    case Staff = 'staff';

    public function ringColor(): string
    {
        return match ($this) {
            UserRole::Admin => 'bg-violet-50 text-violet-700 ring-violet-600/20 dark:bg-violet-500/10 dark:text-violet-400',
            UserRole::Staff => 'bg-zinc-50 text-zinc-700 ring-zinc-600/20 dark:bg-zinc-500/10 dark:text-zinc-400',
        };
    }
}
