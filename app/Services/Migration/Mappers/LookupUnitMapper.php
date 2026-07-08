<?php

namespace App\Services\Migration\Mappers;

use App\Models\LookupUnit;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\EntityMapper;
use App\Services\Migration\MapOutcome;
use Illuminate\Support\Facades\DB;

class LookupUnitMapper implements EntityMapper
{
    public function key(): string
    {
        return 'lookups';
    }

    public function label(): string
    {
        return 'Units';
    }

    public function count(): int
    {
        return DB::connection('legacy')->table('Units')->where('Status', 'A')->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach (
            DB::connection('legacy')->table('Units')
                ->where('Status', 'A')
                ->orderBy('Uid')
                ->lazy($chunkSize) as $row
        ) {
            yield (array) $row;
        }
    }

    /**
     * lookup_units has no legacy_uid column and no updatable fields besides name itself, so the
     * duplicate strategy has nothing to act on here — always create-if-missing by name.
     */
    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $name = trim((string) ($legacyRow['name'] ?? ''));

        if ($name === '') {
            return MapOutcome::Skipped;
        }

        $existing = LookupUnit::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();

        if ($existing) {
            return MapOutcome::Skipped;
        }

        LookupUnit::create(['name' => $name]);

        return MapOutcome::Added;
    }
}
