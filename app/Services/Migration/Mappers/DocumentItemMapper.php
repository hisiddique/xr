<?php

namespace App\Services\Migration\Mappers;

use App\Models\Document;
use App\Models\DocumentItem;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DocumentItemMapper implements BulkEntityMapper
{
    /** @var array<int, int>|null Maps documents.legacy_uid => documents.id */
    private ?array $documentIdByLegacyUid = null;

    public function key(): string
    {
        return 'document_items';
    }

    public function label(): string
    {
        return 'Document Line Items';
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach ($this->baseQuery()->orderBy('DocumentDetails.uid')->lazy($chunkSize) as $row) {
            yield (array) $row;
        }
    }

    /**
     * Resolves each detail row's parent document server-side via a same-connection join
     * (DocumentDetails.Bline/Rtype -> Documents.Bline/Rtype), instead of pulling the whole
     * Documents table into a PHP-side lookup map — that approach used offset pagination
     * over ~490k rows, which gets slower with every page on a remote MSSQL connection.
     *
     * Also restricts to parents that were actually migrated locally (their legacy_uid
     * exists in our documents table), so items belonging to a skipped/excluded document
     * are never fetched over the network in the first place, instead of being pulled and
     * then discarded in transform().
     *
     * Deliberately re-queries the migrated-uid list fresh on every call (cheap local
     * query) rather than reusing loadMapsOnce()'s cache: MigrationRunner calls count()
     * on every mapper up front, before any mapper (including documents) has actually
     * run — caching here would freeze in that empty/early state for the whole run.
     */
    private function baseQuery(): Builder
    {
        $migratedLegacyUids = Document::withTrashed()->whereNotNull('legacy_uid')->pluck('legacy_uid');

        return DB::connection('legacy')->table('DocumentDetails')
            ->join('Documents', function ($join) {
                $join->on('DocumentDetails.bline', '=', 'Documents.bline')
                    ->on('DocumentDetails.rtype', '=', 'Documents.rtype');
            })
            ->whereIn('DocumentDetails.rtype', ['d', 'i', 'r'])
            ->whereIn('Documents.uid', $migratedLegacyUids)
            ->select('DocumentDetails.*', 'Documents.uid as parent_legacy_uid');
    }

    public function targetModel(): string
    {
        return DocumentItem::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return [
            'document_id', 'details', 'is_note', 'quantity', 'price', 'per',
            'line_value', 'discount_percent', 'net_value',
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        $this->loadMapsOnce();

        $parentLegacyUid = $legacyRow['parent_legacy_uid'] ?? null;

        if ($parentLegacyUid === null) {
            return null;
        }

        $documentId = $this->documentIdByLegacyUid[$parentLegacyUid] ?? null;

        if ($documentId === null) {
            return null;
        }

        $value = (float) ($legacyRow['value'] ?? 0);

        return [
            'legacy_uid' => $legacyRow['uid'],
            'document_id' => $documentId,
            'details' => $legacyRow['details'] ?? null,
            'is_note' => false,
            'quantity' => $legacyRow['qty'] ?? 0,
            'price' => $legacyRow['price'] ?? 0,
            'per' => $legacyRow['unitdesc'] ?? null,
            'line_value' => $value,
            // Confirmed against live data: legacy `discount` is a whole-number percentage,
            // e.g. price 21.6 * qty 1 * (1 - 40%) = 12.96 = the stored `value` exactly.
            'discount_percent' => $legacyRow['discount'] ?? 0,
            'net_value' => $value,
        ];
    }

    private function loadMapsOnce(): void
    {
        if ($this->documentIdByLegacyUid !== null) {
            return;
        }

        $this->documentIdByLegacyUid = Document::withTrashed()
            ->whereNotNull('legacy_uid')
            ->pluck('id', 'legacy_uid')
            ->all();
    }

    /**
     * Required by EntityMapper (which BulkEntityMapper extends) but never called by
     * MigrationRunner for bulk mappers — it always uses transform() + upsert() instead.
     * Kept as a correct, working single-row fallback for interface compliance.
     */
    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $existing = DocumentItem::where('legacy_uid', $legacyRow['uid'])->first();

        if ($existing && $strategy === DuplicateStrategy::SkipExisting) {
            return MapOutcome::Skipped;
        }

        $attributes = $this->transform($legacyRow);

        if ($attributes === null) {
            return MapOutcome::Skipped;
        }

        if ($existing) {
            $existing->update($attributes);

            return MapOutcome::Updated;
        }

        DocumentItem::create($attributes);

        return MapOutcome::Added;
    }
}
