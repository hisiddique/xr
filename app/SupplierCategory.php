<?php

namespace App;

enum SupplierCategory: string
{
    case OverheadExpenses = 'overhead_expenses';
    case Trading = 'trading';

    public function label(): string
    {
        return match ($this) {
            SupplierCategory::OverheadExpenses => 'Overhead Expenses',
            SupplierCategory::Trading => 'Trading',
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            SupplierCategory::OverheadExpenses => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400',
            SupplierCategory::Trading => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
        };
    }
}
