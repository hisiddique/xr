<?php

namespace App\Services\Migration\Support;

use Carbon\Carbon;

/**
 * The legacy MSSQL connection (FreeTDS/pdo_dblib) renders date/datetime columns as
 * strings like "Nov 11 2021 12:00:00:AM" — a colon before AM/PM instead of a space —
 * which both Carbon and MySQL's own date parser reject outright. Normalize that one
 * spot and retry before giving up, since it's the only malformed pattern observed.
 */
class LegacyDate
{
    public static function parse(mixed $raw): ?string
    {
        $value = trim((string) ($raw ?? ''));

        if ($value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toDateTimeString();
        } catch (\Throwable) {
            $fixed = preg_replace('/(\d{1,2}:\d{2}:\d{2}):(AM|PM)$/i', '$1 $2', $value);

            if ($fixed === null || $fixed === $value) {
                return null;
            }

            try {
                return Carbon::parse($fixed)->toDateTimeString();
            } catch (\Throwable) {
                return null;
            }
        }
    }
}
