<?php

namespace App\Services\Migration;

use App\Models\Document;
use Illuminate\Support\Facades\Schema;

/**
 * Settles invoices using a client-supplied `t_os_inv` table (with an `invoice`
 * column) listing every invoice number legacy currently considers outstanding.
 * Any migrated invoice (`doc_number LIKE 'INV-%'`) NOT present in that list is
 * flagged `legacy_confirmed_paid` and marked `is_settled` — legacy's absence
 * from the outstanding list is treated as confirmation it has been resolved.
 *
 * Replaces `LegacyOutstandingReconciler`'s AccountEntries-based matching per
 * business decision: the client's own list is now the ground truth.
 *
 * `t_os_inv` is a one-off table the client populates manually and is not part
 * of the standard schema — callers MUST check `tableAvailable()` before calling
 * `plan()`, since `plan()` assumes the table and column exist.
 */
class LegacyOsInvReconciler
{
    private const string TABLE = 't_os_inv';

    private const string SETTLED_NOTE = 'Legacy System Confirmed that the invoice has been settled';

    public function __construct(private ?int $excludeCustomerId) {}

    public function tableAvailable(): bool
    {
        return Schema::hasTable(self::TABLE) && Schema::hasColumn(self::TABLE, 'invoice');
    }

    /**
     * @return array{batch: string, document_ids: list<int>}
     */
    public function plan(string $batch): array
    {
        $documentIds = Document::query()
            ->where('doc_number', 'like', 'INV-%')
            ->where('legacy_confirmed_paid', false)
            ->when($this->excludeCustomerId, fn ($q) => $q->where('customer_id', '!=', $this->excludeCustomerId))
            ->whereNotIn('doc_number', function ($query) {
                $query->select('invoice')->from(self::TABLE)->whereNotNull('invoice');
            })
            ->pluck('id')
            ->all();

        return [
            'batch' => $batch,
            'document_ids' => $documentIds,
        ];
    }

    /**
     * @param  array{document_ids: array<int, int>}  $plan
     */
    public function isEmpty(array $plan): bool
    {
        return $plan['document_ids'] === [];
    }

    /**
     * @param  array{batch: string, document_ids: array<int, int>}  $plan
     * @return int number of documents settled
     */
    public function apply(array $plan): int
    {
        $settled = 0;

        foreach (array_chunk($plan['document_ids'], 1000) as $ids) {
            $settled += Document::whereIn('id', $ids)->update([
                'is_settled' => true,
                'legacy_confirmed_paid' => true,
                'legacy_confirmed_paid_batch' => $plan['batch'],
                'notes' => self::SETTLED_NOTE,
            ]);
        }

        return $settled;
    }
}
