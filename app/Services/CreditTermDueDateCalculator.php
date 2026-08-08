<?php

namespace App\Services;

use Carbon\CarbonInterface;

class CreditTermDueDateCalculator
{
    public static function calculate(?string $creditTermName, CarbonInterface $docDate): ?CarbonInterface
    {
        if ($creditTermName === null || trim($creditTermName) === '') {
            return null;
        }

        $name = strtolower(trim($creditTermName));

        if (str_contains($name, '[d]')) {
            return null;
        }

        if (str_contains($name, 'enter the due date')) {
            return null;
        }

        if (preg_match('/(\d+)\s*days?/i', $name, $matches) === 1) {
            return $docDate->copy()->addDays((int) $matches[1]);
        }

        if (str_contains($name, 'end of the following month')) {
            return $docDate->copy()->addMonthNoOverflow()->endOfMonth();
        }

        if (str_contains($name, 'end of the month')) {
            return $docDate->copy()->endOfMonth();
        }

        if (str_contains($name, 'immediate') || str_contains($name, 'cash on delivery')) {
            return $docDate->copy();
        }

        return null;
    }
}
