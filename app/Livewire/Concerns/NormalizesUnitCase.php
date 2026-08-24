<?php

namespace App\Livewire\Concerns;

trait NormalizesUnitCase
{
    /**
     * Match a stored unit string against the canonical unit list case-insensitively,
     * so "Each" and "each" resolve to whichever casing the lookup table defines.
     *
     * @param  string[]  $units
     */
    protected function normalizeUnitCase(array $units, ?string $per): ?string
    {
        if (! $per) {
            return $per;
        }

        foreach ($units as $unit) {
            if (strcasecmp($unit, $per) === 0) {
                return $unit;
            }
        }

        return $per;
    }
}
