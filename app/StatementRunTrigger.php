<?php

namespace App;

enum StatementRunTrigger: string
{
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Retry = 'retry';

    public function label(): string
    {
        return match ($this) {
            StatementRunTrigger::Scheduled => 'Scheduled',
            StatementRunTrigger::Manual => 'Manual',
            StatementRunTrigger::Retry => 'Retry',
        };
    }
}
