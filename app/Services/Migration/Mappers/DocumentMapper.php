<?php

namespace App\Services\Migration\Mappers;

use App\DocumentStatus;
use App\DocumentType;
use App\Models\Customer;
use App\Models\Document;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\ReportsExcludedRows;
use App\Services\Migration\Support\LegacyDate;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DocumentMapper implements BulkEntityMapper, ReportsExcludedRows
{
    private ?int $createdBy = null;

    /** @var array<int, int>|null Maps customers.legacy_uid => customers.id */
    private ?array $customerIdByLegacyUid = null;

    /** @var array<string, array<int, int>>|null Maps a colliding ref => ordered list of legacy Uids sharing it */
    private ?array $refOrdinals = null;

    public function setCreatedBy(int $userId): static
    {
        $this->createdBy = $userId;

        return $this;
    }

    public function key(): string
    {
        return 'documents';
    }

    public function label(): string
    {
        return 'Delivery Notes, Invoices & Credit Notes';
    }

    public function count(): int
    {
        return $this->baseQuery()->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach ($this->baseQuery()->orderBy('Documents.Uid')->lazy($chunkSize) as $row) {
            yield (array) $row;
        }
    }

    public function excludedCount(): int
    {
        return DB::connection('legacy')->table('Documents')
            ->whereIn('Rtype', ['d', 'i', 'r'])
            ->whereNotExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('CustSupps')
                    ->where('CustSupps.Rtype', 'A')
                    ->whereColumn('CustSupps.Uid', 'Documents.Acctuid');
            })
            ->count();
    }

    public function excludedLabel(): string
    {
        return 'reference a customer that does not exist anywhere in the legacy data';
    }

    private function baseQuery(): Builder
    {
        return DB::connection('legacy')->table('Documents')
            ->join('CustSupps', function ($join) {
                $join->on('Documents.Acctuid', '=', 'CustSupps.Uid')
                    ->where('CustSupps.Rtype', '=', 'A');
            })
            ->whereIn('Documents.Rtype', ['d', 'i', 'r'])
            ->select('Documents.*');
    }

    /** @return array<string, array<int, int>> */
    private function refOrdinals(): array
    {
        if ($this->refOrdinals !== null) {
            return $this->refOrdinals;
        }

        $duplicateRefs = $this->baseQuery()
            ->select('Documents.ref')
            ->groupBy('Documents.ref')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('ref')
            ->all();

        $this->refOrdinals = [];

        if ($duplicateRefs !== []) {
            $rows = $this->baseQuery()
                ->select('Documents.uid', 'Documents.ref')
                ->whereIn('Documents.ref', $duplicateRefs)
                ->orderBy('Documents.uid')
                ->get();

            foreach ($rows as $row) {
                $this->refOrdinals[trim((string) $row->ref)][] = (int) $row->uid;
            }
        }

        return $this->refOrdinals;
    }

    public function targetModel(): string
    {
        return Document::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return [
            'customer_id', 'type', 'order_no', 'doc_date', 'subtotal', 'total_value',
            'vat_amount', 'discount_amount', 'trade_discount', 'show_pricing', 'print_count',
            'status', 'notes', 'doc_number', 'created_by', 'deleted_at',
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        $type = match (strtolower(trim((string) $legacyRow['rtype']))) {
            'd' => DocumentType::DeliveryNote,
            'i' => DocumentType::Invoice,
            'r' => DocumentType::CreditNote,
            default => null,
        };

        if ($type === null) {
            return null;
        }

        if ($this->customerIdByLegacyUid === null) {
            $this->customerIdByLegacyUid = Customer::withTrashed()
                ->whereNotNull('legacy_uid')
                ->pluck('id', 'legacy_uid')
                ->all();
        }

        $customerId = $this->customerIdByLegacyUid[$legacyRow['acctuid']] ?? null;

        if ($customerId === null) {
            return null;
        }

        $goods = (float) ($legacyRow['goods'] ?? 0);
        $value = (float) ($legacyRow['value'] ?? 0);

        $docNumber = trim((string) ($legacyRow['ref'] ?? ''));

        if ($docNumber === '') {
            return null;
        }

        $ordinals = $this->refOrdinals();

        if (isset($ordinals[$docNumber])) {
            $position = array_search((int) $legacyRow['uid'], $ordinals[$docNumber], true);

            if ($position !== false) {
                $docNumber .= '-'.($position + 1);
            }
        }

        $docDate = LegacyDate::parse($legacyRow['date'] ?? null);

        if ($docDate === null) {
            return null;
        }

        return [
            'legacy_uid' => $legacyRow['uid'],
            'customer_id' => $customerId,
            'type' => $type->value,
            'order_no' => $legacyRow['orderno'] ?? null,
            'doc_date' => $docDate,
            'subtotal' => $goods,
            'total_value' => $value,
            'vat_amount' => max(0, $value - $goods),
            'discount_amount' => 0,
            'trade_discount' => 0,
            'show_pricing' => true,
            'print_count' => 0,
            'status' => DocumentStatus::Active->value,
            'notes' => $legacyRow['notes'] ?? null,
            'doc_number' => $docNumber,
            'created_by' => $this->createdBy,
            'deleted_at' => LegacyDate::parse($legacyRow['deleteddate'] ?? null),
        ];
    }

    /**
     * Required by EntityMapper (which BulkEntityMapper extends) but never called by
     * MigrationRunner for bulk mappers — it always uses transform() + upsert() instead.
     * Kept as a correct, working single-row fallback for interface compliance.
     */
    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $existing = Document::withTrashed()->where('legacy_uid', $legacyRow['uid'])->first();

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

        Document::create($attributes);

        return MapOutcome::Added;
    }
}
