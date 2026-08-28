<?php

namespace App;

enum SupplierInvoicePaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Paid',
            self::Partial => 'Partial',
            self::Unpaid => 'Unpaid',
        };
    }

    /**
     * Flux badge colour for this status.
     */
    public function badgeColor(): string
    {
        return match ($this) {
            self::Paid => 'green',
            self::Partial => 'amber',
            self::Unpaid => 'red',
        };
    }
}
