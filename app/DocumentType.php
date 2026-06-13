<?php

namespace App;

enum DocumentType: string
{
    case DeliveryNote = 'DN';
    case Invoice = 'INV';
    case CreditNote = 'CN';

    public function label(): string
    {
        return match ($this) {
            DocumentType::DeliveryNote => 'Delivery Note',
            DocumentType::Invoice => 'Invoice',
            DocumentType::CreditNote => 'Credit Note',
        };
    }
}
