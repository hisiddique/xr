<?php

namespace App\Services\Migration\Mappers;

use App\Models\SupplierDebitNote;
use App\Models\SupplierDebitNoteItem;
use App\Services\Migration\BulkEntityMapper;
use App\Services\Migration\DuplicateStrategy;
use App\Services\Migration\MapOutcome;
use Illuminate\Support\Facades\DB;

class SupplierDebitNoteItemMapper implements BulkEntityMapper
{
    /** @var array<string, int>|null Maps "{Bline}" => legacy Documents.Uid for Rtype='v' */
    private ?array $parentLegacyUidByBline = null;

    /** @var array<int, int>|null Maps supplier_debit_notes.legacy_uid => supplier_debit_notes.id */
    private ?array $debitNoteIdByLegacyUid = null;

    public function key(): string
    {
        return 'supplier_debit_note_items';
    }

    public function label(): string
    {
        return 'Purchase Credit Note Line Items';
    }

    public function count(): int
    {
        return DB::connection('legacy')->table('DocumentDetails')->where('Rtype', 'v')->count();
    }

    public function rows(int $chunkSize): iterable
    {
        foreach (
            DB::connection('legacy')->table('DocumentDetails')
                ->where('Rtype', 'v')
                ->orderBy('Uid')
                ->lazy($chunkSize) as $row
        ) {
            yield (array) $row;
        }
    }

    public function targetModel(): string
    {
        return SupplierDebitNoteItem::class;
    }

    public function uniqueBy(): string
    {
        return 'legacy_uid';
    }

    public function updatableColumns(): array
    {
        return [
            'supplier_debit_note_id',
            'description',
            'quantity',
            'amount',
            'total',
            'sort_order',
        ];
    }

    public function transform(array $legacyRow): ?array
    {
        $this->loadMapsOnce();

        $parentLegacyUid = $this->parentLegacyUidByBline[$legacyRow['bline']] ?? null;

        if ($parentLegacyUid === null) {
            return null;
        }

        $debitNoteId = $this->debitNoteIdByLegacyUid[$parentLegacyUid] ?? null;

        if ($debitNoteId === null) {
            return null;
        }

        $description = trim((string) ($legacyRow['details'] ?? ''));

        return [
            'legacy_uid' => $legacyRow['uid'],
            'supplier_debit_note_id' => $debitNoteId,
            'description' => $description !== '' ? $description : '(no description)',
            'quantity' => $legacyRow['qty'] ?? 1,
            'amount' => $legacyRow['price'] ?? 0,
            'total' => $legacyRow['value'] ?? 0,
            'sort_order' => (int) ($legacyRow['seqno'] ?? 0),
        ];
    }

    public function apply(array $legacyRow, DuplicateStrategy $strategy): MapOutcome
    {
        $attributes = $this->transform($legacyRow);

        if ($attributes === null) {
            return MapOutcome::Skipped;
        }

        $existing = SupplierDebitNoteItem::where('legacy_uid', $legacyRow['uid'])->first();

        if ($existing && $strategy === DuplicateStrategy::SkipExisting) {
            return MapOutcome::Skipped;
        }

        if ($existing) {
            $existing->update($attributes);

            return MapOutcome::Updated;
        }

        SupplierDebitNoteItem::create($attributes);

        return MapOutcome::Added;
    }

    private function loadMapsOnce(): void
    {
        if ($this->parentLegacyUidByBline !== null && $this->debitNoteIdByLegacyUid !== null) {
            return;
        }

        $this->parentLegacyUidByBline = [];

        DB::connection('legacy')->table('Documents')
            ->where('Rtype', 'v')
            ->select('Uid', 'Bline')
            ->orderBy('Uid')
            ->each(function ($row): void {
                $this->parentLegacyUidByBline[$row->bline] = $row->uid;
            });

        $this->debitNoteIdByLegacyUid = SupplierDebitNote::withTrashed()
            ->whereNotNull('legacy_uid')
            ->pluck('id', 'legacy_uid')
            ->all();
    }
}
