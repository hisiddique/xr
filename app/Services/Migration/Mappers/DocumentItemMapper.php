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
        foreach ($this->baseQuery()->lazyById($chunkSize, 'DocumentDetails.uid', 'uid') as $row) {
            yield (array) $row;
        }
    }

    private function baseQuery(): Builder
    {
        return DB::connection('legacy')->table('DocumentDetails')
            ->join('Documents', function ($join) {
                $join->on('DocumentDetails.bline', '=', 'Documents.bline')
                    ->on('DocumentDetails.rtype', '=', 'Documents.rtype');
            })
            ->join('CustSupps', function ($join) {
                $join->on('Documents.Acctuid', '=', 'CustSupps.Uid')
                    ->where('CustSupps.Rtype', '=', 'A');
            })
            ->leftJoin('Units', 'DocumentDetails.unituid', '=', 'Units.uid')
            ->whereIn('DocumentDetails.rtype', ['d', 'i', 'r'])
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
            'details' => $legacyRow['details'] ?? '',
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
