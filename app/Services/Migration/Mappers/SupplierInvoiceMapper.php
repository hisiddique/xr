<?php

namespace App\Services\Migration\Mappers;

use App\Models\Supplier;
use App\Models\SupplierInvoice;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use App\Services\Migration\Support\LegacyDate;
use App\SupplierInvoiceStatus;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceMapper implements BulkEntityMapper
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
        return 'supplier_invoices';
    }

    public function label(): string
    {
        return 'Purchase Invoices';
    }

    public function count(): int
    {
        return DB::connection('legacy')->table('Documents')->where('Rtype', 'c')->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach (
            DB::connection('legacy')->table('Documents')
                ->where('Rtype', 'c')
                ->orderBy('Uid')
                ->lazy($chunkSize) as $row
        ) {
            yield (array) $row;
        }
    }

    public function targetModel(): string
    {
        return SupplierInvoice::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return ['supplier_id', 'invoice_date', 'due_date', 'status', 'notes', 'supplier_invoice_no', 'created_by'];
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

        $supplierInvoiceNo = trim((string) ($legacyRow['ref'] ?? ''));

        if ($supplierInvoiceNo === '') {
            return null;
        }

        $invoiceDate = LegacyDate::parse($legacyRow['date'] ?? null);

        if ($invoiceDate === null) {
            return null;
        }

        return [
            'legacy_uid' => $legacyRow['uid'],
            'supplier_id' => $supplierId,
            'invoice_date' => $invoiceDate,
            'due_date' => LegacyDate::parse($legacyRow['paymtdue'] ?? null),
            'status' => SupplierInvoiceStatus::Posted->value,
            'notes' => $legacyRow['notes'] ?? null,
            'supplier_invoice_no' => $supplierInvoiceNo,
            'created_by' => $this->createdBy,
        ];
    }

    /**
     * Required by EntityMapper (which BulkEntityMapper extends) but never called by
     * MigrationRunner for bulk mappers — it always uses transform() + upsert() instead.
     * Kept as a correct, working single-row fallback for interface compliance.
     * SupplierInvoice has no soft deletes, so a plain query (not withTrashed()) is used.
     */
    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $existing = SupplierInvoice::where('legacy_uid', $legacyRow['uid'])->first();

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

        SupplierInvoice::create($attributes);

        return MapOutcome::Added;
    }
}
