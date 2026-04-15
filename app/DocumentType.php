<?php

namespace App;

enum DocumentType: string
{
    case DeliveryNote = 'DN';
    case Invoice = 'INV';

    public function label(): string
    {
        return match ($this) {
            DocumentType::DeliveryNote => 'Delivery Note',
            DocumentType::Invoice => 'Invoice',
        };
    }
}
