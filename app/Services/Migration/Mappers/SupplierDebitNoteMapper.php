<?php

namespace App\Services\Migration\Mappers;

use App\Models\Supplier;
use App\Models\SupplierDebitNote;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Support\LegacyDate;
use App\SupplierDebitNoteStatus;
use Illuminate\Support\Facades\DB;

class SupplierDebitNoteMapper implements BulkEntityMapper
{
    private ?int $createdBy = null;

    /** @var array<int, int>|null Maps suppliers.legacy_uid => suppliers.id */
    private ?array $supplierIdByLegacyUid = null;

    public function setCreatedBy(int $userId): static
    {
        $this->createdBy = $userId;

        return $this;
    }

    public function key(): string
    {
        return 'supplier_debit_notes';
    }

    public function label(): string
    {
        return 'Purchase Credit Notes';
    }

    public function count(): int
    {
        return DB::connection('legacy')->table('Documents')->where('Rtype', 'v')->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach (
            DB::connection('legacy')->table('Documents')
                ->where('Rtype', 'v')
                ->lazyById($chunkSize, 'Uid', 'uid') as $row
        ) {
            yield (array) $row;
        }
    }

    public function targetModel(): string
    {
        return SupplierDebitNote::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return [
            'supplier_id',
            'doc_date',
            'supplier_invoice_id',
            'notes',
            'subtotal',
            'total',
            'status',
            'created_by',
            'reference',
            'deleted_at',
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        if ($this->supplierIdByLegacyUid === null) {
            $this->supplierIdByLegacyUid = Supplier::withTrashed()
                ->whereNotNull('legacy_uid')
                ->pluck('id', 'legacy_uid')
                ->all();
        }

        $supplierId = $this->supplierIdByLegacyUid[$legacyRow['acctuid']] ?? null;

        if ($supplierId === null) {
            return null;
        }

        $reference = trim((string) ($legacyRow['ref'] ?? ''));

        if ($reference === '') {
            return null;
        }

        $docDate = LegacyDate::parse($legacyRow['date'] ?? null);

        if ($docDate === null) {
            return null;
        }

        return [
            'legacy_uid' => $legacyRow['uid'],
            'supplier_id' => $supplierId,
            'doc_date' => $docDate,
            // No reliable legacy column links a credit note back to a specific purchase invoice.
            'supplier_invoice_id' => null,
            'notes' => $legacyRow['notes'] ?? null,
            'subtotal' => $legacyRow['goods'] ?? 0,
            'total' => $legacyRow['value'] ?? 0,
            'status' => SupplierDebitNoteStatus::Committed->value,
            'created_by' => $this->createdBy,
            'reference' => $reference,
            'deleted_at' => LegacyDate::parse($legacyRow['deleteddate'] ?? null),
        ];
    }

    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $existing = SupplierDebitNote::withTrashed()->where('legacy_uid', $legacyRow['uid'])->first();

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

        SupplierDebitNote::create($attributes);

        return MapOutcome::Added;
    }
}
