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
    private const ID_BATCH_SIZE = 10000;

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
        $total = 0;

        foreach ($this->migratedLegacyUidBatches() as $batch) {
            $total += $this->baseQuery($batch)->count();
        }

        return $total;
    }

    public function rows(int $chunkSize): iterable
    {
        foreach ($this->migratedLegacyUidBatches() as $batch) {
            foreach ($this->baseQuery($batch)->orderBy('DocumentDetails.uid')->lazy($chunkSize) as $row) {
                yield (array) $row;
            }
        }
    }

    /** @return array<int, array<int, int>> */
    private function migratedLegacyUidBatches(): array
    {
        $ids = Document::withTrashed()->whereNotNull('legacy_uid')->pluck('legacy_uid')->all();

        return array_chunk($ids, self::ID_BATCH_SIZE);
    }

    /** @param  array<int, int>  $migratedLegacyUidBatch */
    private function baseQuery(array $migratedLegacyUidBatch): Builder
    {
        return DB::connection('legacy')->table('DocumentDetails')
            ->join('Documents', function ($join) {
                $join->on('DocumentDetails.bline', '=', 'Documents.bline')
                    ->on('DocumentDetails.rtype', '=', 'Documents.rtype');
            })
            ->leftJoin('Units', 'DocumentDetails.unituid', '=', 'Units.uid')
            ->whereIn('DocumentDetails.rtype', ['d', 'i', 'r'])
            ->whereIn('Documents.uid', $migratedLegacyUidBatch)
            ->select('DocumentDetails.*', 'Documents.uid as parent_legacy_uid', 'Units.name as unit_name');
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
            'per' => $legacyRow['unit_name'] ?? null,
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
