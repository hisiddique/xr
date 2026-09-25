<?php

namespace App\Services\Migration;

use App\Models\Document;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reconciles every migrated invoice's local outstanding balance against legacy's
 * confirmation of it. Two populations, three outcomes:
 *
 * Invoices with an `AccountEntries` row (legacy's own osvalue is known):
 *  - matches (|delta| within tolerance): left untouched.
 *  - shortfall (legacy says this is paid down further than we show): rather than
 *    fabricating a payment or write-off, the invoice is flagged
 *    `legacy_confirmed_paid` — a marker meaning "legacy confirms this is
 *    resolved, even though we hold no evidence explaining how." No payment,
 *    allocation, or write-off row is created.
 *  - over-applied locally (we show LESS outstanding than legacy): migrated
 *    allocations against the invoice are shrunk / soft-deleted until the balance
 *    rises to the legacy figure, and every touched payment is marked exhausted.
 *    Unchanged from before — this direction has real evidence to correct
 *    against, so it still edits real allocations rather than flagging.
 *
 * Invoices with no `AccountEntries` row at all (legacy has no osvalue fact for
 * this invoice — confirmed empirically that legacy drops the row once nothing
 * is left open, for both genuinely-resolved and genuinely-still-owed invoices
 * alike, so absence alone proves nothing): flagged `legacy_confirmed_paid` only
 * when this invoice's own already-migrated evidence (real payment allocations +
 * credit allocations + write-offs) already sums to the full total_value. An
 * invoice with no row and no such evidence is left untouched — there is nothing
 * to reconcile it against.
 *
 * `legacy_confirmed_paid_batch` records which run set the flag, so `revert()`
 * can undo only this run's flags rather than every flagged invoice.
 *
 * Must run AFTER LegacyPaymentReconciler, LegacyConversionReconciler,
 * LegacyCreditNoteReconciler and LegacyWriteOffReconciler — it reads the local
 * credits and write-offs those reconcilers create. MORR is excluded via
 * `$excludeCustomerId`, resolved and supplied by the caller.
 */
class LegacyOutstandingReconciler
{
    private const float TOLERANCE = 0.01;

    private const int REPORT_SAMPLE_LIMIT = 100;

    public function __construct(
        private int $userId,
        private ?int $excludeCustomerId,
    ) {}

    /**
     * @return array{
     *     batch: string,
     *     flag_document_ids: list<int>,
     *     allocation_reductions: list<array{id: int, new_amount: float}>,
     *     allocation_deletions: list<int>,
     *     exhaust_payment_ids: list<int>,
     *     flagged_from_row_count: int, flagged_from_row_total: float, flagged_from_row_samples: list<string>,
     *     flagged_from_no_row_count: int, flagged_from_no_row_total: float, flagged_from_no_row_samples: list<string>,
     *     reduced_count: int,
     *     matched_count: int,
     *     ambiguous_ref_count: int,
     *     unreducible: array{count: int, sample: list<array{doc_number: ?string, our_os: float, legacy_os: float}>},
     * }
     */
    public function plan(string $batch): array
    {
        [$osvalueByLocalDocId, $ambiguousRefCount] = $this->resolveLegacyOutstanding();

        $flagDocumentIds = [];
        $allocationReductions = [];
        $allocationDeletions = [];
        $exhaustPaymentIds = [];

        $flaggedFromRowCount = 0;
        $flaggedFromRowTotal = 0.0;
        $flaggedFromRowSamples = [];
        $reducedCount = 0;
        $matchedCount = 0;
        $unreducible = ['count' => 0, 'sample' => []];

        foreach ($this->loadDocs(array_keys($osvalueByLocalDocId)) as $doc) {
            if ($doc->legacy_confirmed_paid) {
                continue;
            }

            $legacyOs = $osvalueByLocalDocId[$doc->id];
            $ourOs = $this->computeOurOs($doc);
            $delta = round($ourOs['balance'] - $legacyOs, 2);

            if (abs($delta) <= self::TOLERANCE) {
                $matchedCount++;

                continue;
            }

            if ($delta > self::TOLERANCE) {
                $flagDocumentIds[] = $doc->id;
                $flaggedFromRowCount++;
                $flaggedFromRowTotal = round($flaggedFromRowTotal + $delta, 2);
                if (count($flaggedFromRowSamples) < 10) {
                    $flaggedFromRowSamples[] = $doc->doc_number;
                }

                continue;
            }

            $reduceBy = round(-$delta, 2);
            $remaining = $reduceBy;

            $allocs = DB::table('payment_allocations as pa')
                ->join('payments as p', 'p.id', '=', 'pa.payment_id')
                ->where('pa.document_id', $doc->id)
                ->whereNull('pa.deleted_at')
                ->whereNotNull('p.legacy_uid')
                ->orderByDesc('pa.allocated_amount')
                ->orderBy('pa.id')
                ->get(['pa.id', 'pa.allocated_amount', 'pa.payment_id']);

            foreach ($allocs as $a) {
                if ($remaining <= self::TOLERANCE) {
                    break;
                }

                $cut = min($remaining, (float) $a->allocated_amount);
                $newAmount = round((float) $a->allocated_amount - $cut, 2);

                if ($newAmount <= self::TOLERANCE) {
                    $allocationDeletions[] = (int) $a->id;
                } else {
                    $allocationReductions[] = ['id' => (int) $a->id, 'new_amount' => $newAmount];
                }

                $exhaustPaymentIds[(int) $a->payment_id] = true;
                $remaining = round($remaining - $cut, 2);
            }

            if ($remaining > self::TOLERANCE) {
                $unreducible['count']++;
                if (count($unreducible['sample']) < self::REPORT_SAMPLE_LIMIT) {
                    $unreducible['sample'][] = [
                        'doc_number' => $doc->doc_number,
                        'our_os' => $ourOs['balance'],
                        'legacy_os' => $legacyOs,
                    ];
                }

                continue;
            }

            $reducedCount++;
        }

        [$flaggedFromNoRowCount, $flaggedFromNoRowTotal, $flaggedFromNoRowSamples, $noRowFlagIds] =
            $this->planNoRowInvoices(array_keys($osvalueByLocalDocId));

        $flagDocumentIds = [...$flagDocumentIds, ...$noRowFlagIds];

        return [
            'batch' => $batch,
            'flag_document_ids' => $flagDocumentIds,
            'allocation_reductions' => $allocationReductions,
            'allocation_deletions' => $allocationDeletions,
            'exhaust_payment_ids' => array_keys($exhaustPaymentIds),
            'flagged_from_row_count' => $flaggedFromRowCount,
            'flagged_from_row_total' => $flaggedFromRowTotal,
            'flagged_from_row_samples' => $flaggedFromRowSamples,
            'flagged_from_no_row_count' => $flaggedFromNoRowCount,
            'flagged_from_no_row_total' => $flaggedFromNoRowTotal,
            'flagged_from_no_row_samples' => $flaggedFromNoRowSamples,
            'reduced_count' => $reducedCount,
            'matched_count' => $matchedCount,
            'ambiguous_ref_count' => $ambiguousRefCount,
            'unreducible' => $unreducible,
        ];
    }

    /**
     * @param  array{flag_document_ids: array, allocation_reductions: array, allocation_deletions: array}  $plan
     */
    public function isEmpty(array $plan): bool
    {
        return $plan['flag_document_ids'] === []
            && $plan['allocation_reductions'] === []
            && $plan['allocation_deletions'] === [];
    }

    /**
     * @param  array{
     *     batch: string,
     *     flag_document_ids: array<int, int>,
     *     allocation_reductions: array<int, array{id: int, new_amount: float}>,
     *     allocation_deletions: array<int, int>,
     *     exhaust_payment_ids: array<int, int>,
     * }  $plan
     */
    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan) {
            if ($plan['flag_document_ids'] !== []) {
                foreach (array_chunk($plan['flag_document_ids'], 1000) as $ids) {
                    Document::whereIn('id', $ids)->update([
                        'legacy_confirmed_paid' => true,
                        'legacy_confirmed_paid_batch' => $plan['batch'],
                    ]);
                }
            }

            foreach ($plan['allocation_reductions'] as $reduction) {
                DB::table('payment_allocations')
                    ->where('id', $reduction['id'])
                    ->update(['allocated_amount' => $reduction['new_amount'], 'updated_at' => now()]);
            }

            foreach (array_chunk($plan['allocation_deletions'], 1000) as $ids) {
                DB::table('payment_allocations')->whereIn('id', $ids)->update(['deleted_at' => now(), 'updated_at' => now()]);
            }

            if ($plan['exhaust_payment_ids'] !== []) {
                foreach (array_chunk($plan['exhaust_payment_ids'], 1000) as $ids) {
                    DB::table('payments')->whereIn('id', $ids)->update(['is_exhausted' => true, 'updated_at' => now()]);
                }
            }
        });
    }

    /**
     * Clears only this run's flags (`legacy_confirmed_paid_batch = $batch`) — it
     * does NOT restore allocations shrunk or soft-deleted by the over-applied
     * reduce path, nor un-exhaust payments it marked exhausted, matching the
     * same documented limitation the previous synthetic-row revert had.
     *
     * @return array{flags_cleared: int}
     */
    public function revert(string $batch): array
    {
        $flagsCleared = Document::where('legacy_confirmed_paid_batch', $batch)
            ->update(['legacy_confirmed_paid' => false, 'legacy_confirmed_paid_batch' => null]);

        return ['flags_cleared' => $flagsCleared];
    }

    /**
     * @param  array<int, int>  $excludeLocalIds  local ids already covered by the row-based pass
     * @return array{0: int, 1: float, 2: list<string>, 3: list<int>}
     */
    private function planNoRowInvoices(array $excludeLocalIds): array
    {
        // A whereNotIn() here would need one placeholder per row-based-pass id, which
        // can exceed MySQL's 65535-placeholder-per-statement cap on a full production
        // dataset — fetch every remaining candidate instead and exclude in PHP.
        $excludeSet = array_flip($excludeLocalIds);

        $candidateIds = Document::query()
            ->invoices()
            ->whereNotNull('legacy_uid')
            ->when($this->excludeCustomerId, fn ($q) => $q->where('customer_id', '!=', $this->excludeCustomerId))
            ->where('legacy_confirmed_paid', false)
            ->pluck('id')
            ->reject(fn ($id) => isset($excludeSet[$id]))
            ->values()
            ->all();

        $flagIds = [];
        $count = 0;
        $total = 0.0;
        $samples = [];

        foreach ($this->loadDocs($candidateIds) as $doc) {
            $ourOs = $this->computeOurOs($doc);

            if (abs($ourOs['balance']) > self::TOLERANCE) {
                continue;
            }

            if ($ourOs['allocated'] <= 0.001 && $ourOs['credited'] <= 0.001 && $ourOs['writtenOff'] <= 0.001) {
                // Nothing at all was ever recorded against this invoice locally —
                // a zero balance here just means total_value itself is ~0, not
                // that anything was resolved. Leave it alone.
                continue;
            }

            $flagIds[] = $doc->id;
            $count++;
            $total = round($total + (float) $doc->total_value, 2);
            if (count($samples) < 10) {
                $samples[] = $doc->doc_number;
            }
        }

        return [$count, $total, $samples, $flagIds];
    }

    /**
     * @param  array<int, int>  $ids
     * @return Collection<int, Document>
     */
    private function loadDocs(array $ids): Collection
    {
        $docs = collect();

        foreach (collect($ids)->chunk(20000) as $chunk) {
            $docs = $docs->merge(
                Document::query()
                    ->whereIn('id', $chunk->all())
                    ->when($this->excludeCustomerId, fn ($q) => $q->where('customer_id', '!=', $this->excludeCustomerId))
                    ->withSum(['paymentAllocations' => fn ($q) => $q->whereNull('deleted_at')], 'allocated_amount')
                    ->withSum(['creditAllocationsReceived' => fn ($q) => $q->whereNull('deleted_at')], 'amount')
                    ->withSum(['writeOffs' => fn ($q) => $q->whereNull('deleted_at')], 'amount')
                    ->get(['id', 'customer_id', 'doc_number', 'doc_date', 'total_value', 'legacy_confirmed_paid'])
            );
        }

        return $docs;
    }

    /**
     * @return array{balance: float, allocated: float, credited: float, writtenOff: float}
     */
    private function computeOurOs(Document $doc): array
    {
        $allocated = round((float) ($doc->payment_allocations_sum_allocated_amount ?? 0), 2);
        $credited = round((float) ($doc->credit_allocations_received_sum_amount ?? 0), 2);
        $writtenOff = round((float) ($doc->write_offs_sum_amount ?? 0), 2);
        $balance = round((float) $doc->total_value - $allocated - $credited - $writtenOff, 2);

        return [
            'balance' => $balance,
            'allocated' => $allocated,
            'credited' => $credited,
            'writtenOff' => $writtenOff,
        ];
    }

    /**
     * Resolves legacy `AccountEntries.osvalue` (rtype='a', posttype=85) to local
     * invoice ids via legacy `Documents` (rtype='i') ref matching. Refs whose ref
     * string maps to more than one legacy Documents row are dropped as ambiguous
     * and counted; refs with no legacy Documents row, or whose legacy doc was not
     * migrated, are silently skipped.
     *
     * @return array{0: array<int, float>, 1: int}
     */
    private function resolveLegacyOutstanding(): array
    {
        $entries = DB::connection('legacy')->table('AccountEntries')
            ->where('rtype', 'a')
            ->where('posttype', 85)
            ->select(['invno', 'osvalue'])
            ->get();

        $documentsByRef = DB::connection('legacy')->table('Documents')
            ->where('rtype', 'i')
            ->select(['uid', 'ref'])
            ->get()
            ->groupBy(fn ($r) => trim((string) $r->ref));

        $localIdByLegacyUid = Document::query()->invoices()->whereNotNull('legacy_uid')->pluck('id', 'legacy_uid')->all();

        $osvalueByLocalDocId = [];
        $ambiguousRefCount = 0;

        foreach ($entries as $entry) {
            $ref = trim((string) $entry->invno);
            $matches = $documentsByRef->get($ref);

            if ($matches === null) {
                continue;
            }

            if ($matches->count() > 1) {
                $ambiguousRefCount++;

                continue;
            }

            $localId = $localIdByLegacyUid[$matches->first()->uid] ?? null;

            if ($localId === null) {
                continue;
            }

            $osvalueByLocalDocId[$localId] = round((float) $entry->osvalue, 2);
        }

        return [$osvalueByLocalDocId, $ambiguousRefCount];
    }
}
