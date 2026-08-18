<?php

namespace App;

enum ExportJobStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            ExportJobStatus::Pending => 'Pending',
            ExportJobStatus::Running => 'Running',
            ExportJobStatus::Completed => 'Completed',
            ExportJobStatus::Failed => 'Failed',
            ExportJobStatus::Cancelled => 'Cancelled',
        };
    }
}
