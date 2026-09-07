<?php

namespace App\Services\Archive;

use Illuminate\Support\Facades\DB;

class ArchiveScopeResolver
{
    private const int ID_BATCH = 2000;

    public function __construct(private readonly ArchiveTableRegistry $registry) {}

    /**
     * Ordered list of physical tables touched when the given groups are selected:
     * each selected group's parent table plus its dependent closure, de-duped,
     * ordered parents-before-children.
     *
     * @param  list<string>  $selectedGroups
     * @return list<string>
     */
    public function tablesFor(array $selectedGroups): array
    {
        $groups = $this->registry->selectableGroups();

        $parentTables = [];

        foreach ($selectedGroups as $groupKey) {
            if (isset($groups[$groupKey])) {
                $parentTables[] = $groups[$groupKey]['table'];
            }
        }

        $tables = $parentTables;

        foreach ($parentTables as $parent) {
            foreach ($this->registry->dependentTables($parent) as $dependent) {
                $tables[] = $dependent['table'];
            }
        }

        $tables = array_values(array_unique($tables));

        $position = array_flip($this->registry->copyOrder());

        $tables = array_values(array_filter(
            $tables,
            static fn (string $table): bool => isset($position[$table]),
        ));

        usort($tables, static fn (string $a, string $b): int => $position[$a] <=> $position[$b]);

        $forbidden = array_intersect($tables, ArchiveTableRegistry::NEVER_ARCHIVABLE);

        if ($forbidden !== []) {
            throw new \LogicException('Archive scope resolved a protected table: '.implode(', ', $forbidden));
        }

        return $tables;
    }

    /**
     * The full per-table in-scope row-id map, keyed by table, in the same order
     * as tablesFor(). Parent tables resolved by created_at window (+ documents
     * conversion/credit graph-closure); dependent tables resolved by FK edges
     * pointing at an already-resolved parent.
     *
     * @param  list<string>  $selectedGroups
     * @param  string  $dateFrom  Y-m-d inclusive
     * @param  string  $dateTo  Y-m-d inclusive
     * @param  string|null  $connection  defaults to config('database.default')
     * @return array<string, list<int>>
     */
    public function resolve(array $selectedGroups, string $dateFrom, string $dateTo, ?string $connection = null): array
    {
        $connection ??= (string) config('database.default');

        $groups = $this->registry->selectableGroups();

        $parentTables = [];

        foreach ($selectedGroups as $groupKey) {
            if (isset($groups[$groupKey])) {
                $parentTables[] = $groups[$groupKey]['table'];
            }
        }

        /** @var array<string, list<int>> $inScopeIds */
        $inScopeIds = [];

        foreach ($this->tablesFor($selectedGroups) as $table) {
            $inScopeIds[$table] = in_array($table, $parentTables, true)
                ? $this->resolveParentIds($connection, $table, $dateFrom, $dateTo)
                : $this->resolveDependentIds($connection, $table, $inScopeIds);
        }

        return $inScopeIds;
    }

    /**
     * Querying the query builder directly bypasses model soft-delete scopes — the
     * required withTrashed() behavior for the archive window.
     *
     * @return list<int>
     */
    private function resolveParentIds(string $connection, string $table, string $dateFrom, string $dateTo): array
    {
        $ids = DB::connection($connection)->table($table)
            ->whereBetween('created_at', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        if ($table === 'documents') {
            $known = array_flip($ids);

            // Fixpoint over the conversion/credit graph: each pass only needs to
            // follow edges out of the ids discovered in the previous pass, so the
            // worklist stays bounded even when $known grows to 100k+.
            $worklist = array_values(array_unique($ids));

            while ($worklist !== []) {
                $newlyFound = [];

                foreach (array_chunk($worklist, self::ID_BATCH) as $batch) {
                    $linked = DB::connection($connection)->table('documents')
                        ->whereIn('id', $batch)
                        ->get(['converted_from_id', 'credited_invoice_id']);

                    foreach ($linked as $row) {
                        foreach ([$row->converted_from_id, $row->credited_invoice_id] as $linkedId) {
                            if ($linkedId !== null && ! isset($known[(int) $linkedId])) {
                                $known[(int) $linkedId] = true;
                                $newlyFound[] = (int) $linkedId;
                            }
                        }
                    }
                }

                $worklist = $newlyFound;
            }

            $ids = array_map('intval', array_keys($known));
        }

        return $ids;
    }

    /**
     * A child row is in scope only via its single declared owning FK; a pivot row
     * only when every endpoint points at an already-in-scope parent. Any other FK
     * the row holds stays a pure reference.
     *
     * @param  array<string, list<int>>  $inScopeIds
     * @return list<int>
     */
    private function resolveDependentIds(string $connection, string $table, array $inScopeIds): array
    {
        $owned = $this->registry->ownedBy($table);

        if ($owned !== null) {
            if (! isset($inScopeIds[$owned['references']])) {
                return [];
            }

            return $this->pluckIdsInBatches($connection, $table, [
                $owned['column'] => $inScopeIds[$owned['references']],
            ]);
        }

        $endpoints = $this->registry->pivotEndpoints($table);

        if ($endpoints !== null) {
            $constraints = [];

            foreach ($endpoints as $endpoint) {
                if (! isset($inScopeIds[$endpoint['references']])) {
                    return [];
                }

                $constraints[$endpoint['column']] = $inScopeIds[$endpoint['references']];
            }

            return $this->pluckIdsInBatches($connection, $table, $constraints);
        }

        return [];
    }

    /**
     * Run the child query once per cartesian combination of ID_BATCH-sized chunks
     * of the given ANDed id constraints, so no single query binds an unbounded
     * placeholder list, then union the plucked ids.
     *
     * @param  array<string, list<int>>  $constraints  column => full id list (all ANDed)
     * @return list<int>
     */
    private function pluckIdsInBatches(string $connection, string $table, array $constraints): array
    {
        $batchedLists = [];

        foreach ($constraints as $column => $ids) {
            $batchedLists[$column] = array_chunk($ids, self::ID_BATCH) ?: [[]];
        }

        $combinations = [[]];

        foreach ($batchedLists as $column => $batches) {
            $next = [];

            foreach ($combinations as $prefix) {
                foreach ($batches as $batch) {
                    $next[] = $prefix + [$column => $batch];
                }
            }

            $combinations = $next;
        }

        $found = [];

        foreach ($combinations as $combination) {
            $query = DB::connection($connection)->table($table);

            foreach ($combination as $column => $batch) {
                $query->whereIn($column, $batch);
            }

            foreach ($query->pluck('id') as $id) {
                $found[(int) $id] = true;
            }
        }

        return array_map('intval', array_keys($found));
    }
}
