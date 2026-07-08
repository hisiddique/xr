<?php

namespace App\Services\Migration;

enum MapOutcome: string
{
    case Added = 'added';
    case Updated = 'updated';
    case Skipped = 'skipped';
}
