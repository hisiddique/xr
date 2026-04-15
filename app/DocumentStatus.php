<?php

namespace App;

enum DocumentStatus: string
{
    case Active = 'active';
    case Converted = 'converted';
    case Emailed = 'emailed';

    public function label(): string
    {
        return match ($this) {
            DocumentStatus::Active => 'Active',
            DocumentStatus::Converted => 'Converted',
            DocumentStatus::Emailed => 'Emailed',
        };
    }
}
