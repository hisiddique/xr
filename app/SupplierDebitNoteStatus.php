<?php

namespace App;

enum SupplierDebitNoteStatus: string
{
    case Committed = 'committed';

    public function label(): string
    {
        return match ($this) {
            SupplierDebitNoteStatus::Committed => 'Committed',
        };
    }

    public function ringColor(): string
    {
        return match ($this) {
            SupplierDebitNoteStatus::Committed => 'ring-blue-500/30 bg-blue-50 text-blue-700 dark:ring-blue-400/20 dark:bg-blue-400/10 dark:text-blue-400',
        };
    }
}
