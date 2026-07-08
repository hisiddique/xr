<?php

namespace App\Services\Migration;

use Illuminate\Database\Eloquent\Model;

/**
 * An EntityMapper that supports writing a whole chunk in a small, fixed number of
 * queries (one existence-check + one upsert) instead of per-row create/update calls.
 * Used by the high-volume mappers (customers, suppliers, documents, document_items,
 * supplier invoices/items, supplier debit notes/items) — the low-volume ones
 * (lookups, settings) stay on the simpler per-row EntityMapper::apply() path since
 * their write pattern doesn't fit a uniform upsert (name-matching, key/value writes).
 */
interface BulkEntityMapper extends EntityMapper
{
    /** @return class-string<Model> */
    public function targetModel(): string;

    /** Column used to detect an existing row across a batch — always 'legacy_uid' today. */
    public function uniqueBy(): string;

    /**
     * Columns to overwrite when a row with a matching uniqueBy() value already exists.
     * Must NOT include the uniqueBy() column itself.
     *
     * @return array<int, string>
     */
    public function updatableColumns(): array;

    /**
     * Transform one legacy row into a full, upsert-ready attributes array (every mapped
     * column present with a real value — never a conditionally-omitted key, since a bulk
     * upsert requires a uniform column set across the whole batch), or null if this row
     * is structurally invalid/orphaned and should be skipped without a write attempt.
     *
     * Must NOT perform new DB queries per call — any cross-referencing (e.g. resolving a
     * parent id) must use an in-memory map built once by the mapper (see the lazy preload
     * pattern already used for parent/document lookups), otherwise batching loses its point.
     *
     * Values must be raw, DB-ready values (e.g. an enum's ->value, not the enum instance;
     * a 'Y-m-d' date string, not a Carbon instance) since upsert() bypasses Eloquent's own
     * casting — casts only apply on read, never during the upsert write itself.
     *
     * @param  array<string, mixed>  $legacyRow
     * @return array<string, mixed>|null
     */
    public function transform(array $legacyRow): ?array;
}
