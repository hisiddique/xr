<?php

namespace App;

enum PaymentSourceType: string
{
    case Cash = 'cash';
    case CreditNote = 'credit_note';
    case OverPayment = 'over_payment';

    public function label(): string
    {
        return match ($this) {
            PaymentSourceType::Cash => 'Cash',
            PaymentSourceType::CreditNote => 'Credit Notes',
            PaymentSourceType::OverPayment => 'Available Over Payments',
        };
    }
}
