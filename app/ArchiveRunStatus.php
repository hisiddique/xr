<?php

namespace App;

enum ArchiveRunStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            ArchiveRunStatus::Pending => 'Pending',
            ArchiveRunStatus::Running => 'Running',
            ArchiveRunStatus::Completed => 'Completed',
            ArchiveRunStatus::Failed => 'Failed',
            ArchiveRunStatus::Cancelled => 'Cancelled',
        };
    }
}
