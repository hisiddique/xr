<?php

namespace App;

enum StatementSubjectType: string
{
    case Customer = 'customer';
    case Supplier = 'supplier';

    public function label(): string
    {
        return match ($this) {
            StatementSubjectType::Customer => 'Customer',
            StatementSubjectType::Supplier => 'Supplier',
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            StatementSubjectType::Customer => 'bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-400',
            StatementSubjectType::Supplier => 'bg-teal-50 text-teal-700 ring-teal-600/20 dark:bg-teal-500/10 dark:text-teal-400',
        };
    }
}
