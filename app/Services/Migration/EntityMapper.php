<?php

namespace App\Services\Migration;

interface EntityMapper
{
    /** Stable identifier used in migration_run_tables.entity and UI selection, e.g. 'customers'. */
    public function key(): string;

    /** Human label for the UI, e.g. 'Customers'. */
    public function label(): string;

    /** Total row count in the legacy source for this entity (drives the progress bar denominator). */
    public function count(): int;

    /**
     * Yield legacy rows as associative arrays, chunked internally at $chunkSize using the legacy
     * connection's cursor/chunking so large tables (e.g. 3.9M rows) don't load into memory at once.
     *
     * @return iterable<array<string, mixed>>
     */
    public function rows(int $chunkSize): iterable;

    /**
     * Map and persist a single legacy row into the target Laravel table, honoring the duplicate
     * strategy (match by the row's legacy unique id; update all mapped fields if UpdateExisting,
     * leave untouched and return Skipped if SkipExisting and a match already exists).
     * Must throw on unrecoverable failure (e.g. DB constraint violation) — callers catch and count
     * it as failed. Rows that are structurally invalid for this entity (e.g. an unrecognized
     * discriminator/type code) should be handled by returning MapOutcome::Skipped rather than throwing,
     * since that is expected/anticipated data quality noise, not a real failure.
     */
    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome;
}
