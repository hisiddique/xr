<?php

namespace App\Services\Migration\Mappers;

use App\Models\SupplierInvoice;
use App\Models\SupplierInvoiceItem;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use Illuminate\Support\Facades\DB;

class SupplierInvoiceItemMapper implements BulkEntityMapper
{
    /** @var array<string, int>|null Maps "{Bline}" => legacy Documents.Uid for Rtype='c' */
    private ?array $parentLegacyUidByBline = null;

    /** @var array<int, int>|null Maps supplier_invoices.legacy_uid => supplier_invoices.id */
    private ?array $supplierInvoiceIdByLegacyUid = null;

    public function key(): string
    {
        return 'supplier_invoice_items';
    }

    public function label(): string
    {
        return 'Purchase Invoice Line Items';
    }

    public function count(): int
    {
        return DB::connection('legacy')->table('DocumentDetails')->where('Rtype', 'c')->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach (
            DB::connection('legacy')->table('DocumentDetails')
                ->where('Rtype', 'c')
                ->orderBy('Uid')
                ->lazy($chunkSize) as $row
        ) {
            yield (array) $row;
        }
    }

    public function targetModel(): string
    {
        return SupplierInvoiceItem::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return ['supplier_invoice_id', 'product_code', 'quantity', 'unit_amount', 'vat_applicable', 'line_total', 'sort_order'];
    }

    public function transform(array $legacyRow): ?array
    {
        $this->loadMapsOnce();

        $parentLegacyUid = $this->parentLegacyUidByBline[$legacyRow['bline']] ?? null;

        if ($parentLegacyUid === null) {
            return null;
        }

        $supplierInvoiceId = $this->supplierInvoiceIdByLegacyUid[$parentLegacyUid] ?? null;

        if ($supplierInvoiceId === null) {
            return null;
        }

        return [
            'legacy_uid' => $legacyRow['uid'],
            'supplier_invoice_id' => $supplierInvoiceId,
            'product_code' => $legacyRow['stkcode'] ?? null,
            'quantity' => $legacyRow['qty'] ?? 1,
            'unit_amount' => $legacyRow['price'] ?? 0,
            // No reliable legacy line-level VAT signal exists; default to non-applicable rather than guess.
            'vat_applicable' => false,
            'line_total' => $legacyRow['value'] ?? 0,
            'sort_order' => (int) ($legacyRow['seqno'] ?? 0),
        ];
    }

    private function loadMapsOnce(): void
    {
        if ($this->parentLegacyUidByBline !== null && $this->supplierInvoiceIdByLegacyUid !== null) {
            return;
        }

        $this->parentLegacyUidByBline = [];

        DB::connection('legacy')->table('Documents')
            ->where('Rtype', 'c')
            ->select('Uid', 'Bline')
            ->orderBy('Uid')
            ->each(function ($row): void {
                $this->parentLegacyUidByBline[$row->bline] = $row->uid;
            });

        $this->supplierInvoiceIdByLegacyUid = SupplierInvoice::query()
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
        $existing = SupplierInvoiceItem::where('legacy_uid', $legacyRow['uid'])->first();

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

        SupplierInvoiceItem::create($attributes);

        return MapOutcome::Added;
    }
}
