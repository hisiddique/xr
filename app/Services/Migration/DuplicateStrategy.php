<?php

namespace App\Services\Migration;

enum DuplicateStrategy: string
{
    case UpdateExisting = 'update_existing';
    case SkipExisting = 'skip_existing';
}
