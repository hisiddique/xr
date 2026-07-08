<?php

namespace App;

enum MigrationRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            MigrationRunStatus::Pending => 'Pending',
            MigrationRunStatus::Running => 'Running',
            MigrationRunStatus::Completed => 'Completed',
            MigrationRunStatus::Failed => 'Failed',
            MigrationRunStatus::Cancelled => 'Cancelled',
        };
    }
}
