<?php

namespace App\Services\Migration\Mappers;

use App\Models\LookupUnit;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\EntityMapper;
use App\Services\Migration\MapOutcome;
use Illuminate\Database\Query\Builder;
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
        return $this->baseQuery()->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach ($this->baseQuery()->orderBy('uid')->lazy($chunkSize) as $row) {
            yield (array) $row;
        }
    }

    /**
     * Matches the legacy app's own UnitDropdown() filter (DatabaseCustom/Document.cs):
     * "AS".Contains(Status) && Recstate == "A" — i.e. Status must be 'A' or 'S', and
     * Recstate 'A' means not soft-deleted. Confirmed against live data: the only
     * Status='A' row is actually Recstate='D' (deleted), while every real active unit
     * is Status='S' + Recstate='A' — a plain Status='A' filter picks the wrong row.
     */
    private function baseQuery(): Builder
    {
        return DB::connection('legacy')->table('Units')
            ->whereIn('status', ['A', 'S'])
            ->where('recstate', 'A');
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
