<?php

namespace App;

enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case Posted = 'posted';

    public function label(): string
    {
        return match ($this) {
            SupplierInvoiceStatus::Draft => 'Draft',
            SupplierInvoiceStatus::Posted => 'Posted',
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            SupplierInvoiceStatus::Draft => 'bg-amber-50 text-amber-700 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400',
            SupplierInvoiceStatus::Posted => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400',
        };
    }
}
