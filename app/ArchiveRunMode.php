<?php

namespace App;

enum ArchiveRunMode: string
{
    case Archive = 'archive';
    case Cleanup = 'cleanup';

    public function label(): string
    {
        return match ($this) {
            ArchiveRunMode::Archive => 'Archive',
            ArchiveRunMode::Cleanup => 'Cleanup',
        };
    }
}
