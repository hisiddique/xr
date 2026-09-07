<?php

namespace App\Services\Migration;

use App\Models\Document;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Forces every migrated invoice's local outstanding balance to equal legacy's
 * `AccountEntries.osvalue` for that invoice. Four cases per invoice:
 *
 *  - matches (|delta| within tolerance): left untouched.
 *  - shortfall, nothing allocated yet (Path A): a backdated synthetic cash
 *    payment for the delta plus a matching allocation.
 *  - shortfall, something already allocated (Path B): a backdated write-off for
 *    the delta (a second payment on top of real allocations would double-count).
 *  - over-applied locally (we show LESS outstanding than legacy): migrated
 *    allocations against the invoice are shrunk / soft-deleted until the balance
 *    rises to the legacy figure, and every touched payment is marked exhausted.
 *
 * Invoices with no `AccountEntries` row never enter the plan and are left
 * completely alone by design. Synthetic payments carry `legacy_uid = null` on
 * purpose so `LegacyPaymentReconciler::apply()`'s `whereNotNull('legacy_uid')`
 * allocation purge never removes them.
 *
 * Must run AFTER LegacyPaymentReconciler, LegacyConversionReconciler,
 * LegacyCreditNoteReconciler and LegacyWriteOffReconciler — it reads the local
 * credits and write-offs those reconcilers create. MORR is excluded via
 * `$excludeCustomerId`, resolved and supplied by the caller.
 */
class LegacyOutstandingReconciler
{
    private const string TAG_TEMPLATE = '[LEGACY-RECON %s] Confirmed from legacy system that these invoices are PAID';

    private const float TOLERANCE = 0.01;

    private const int REPORT_SAMPLE_LIMIT = 100;

    public function __construct(
        private int $userId,
        private ?int $excludeCustomerId,
    ) {}

    /**
     * @return array{
     *     batch: string,
     *     payment_rows: list<array<string, mixed>>,
     *     pending_allocations: array<string, array{document_id: int, allocated_amount: float, ts: string}>,
     *     write_off_rows: list<array<string, mixed>>,
     *     allocation_reductions: list<array{id: int, new_amount: float}>,
     *     allocation_deletions: list<int>,
     *     exhaust_payment_ids: list<int>,
     *     path_a_count: int, path_a_total: float, path_a_samples: list<string>,
     *     path_b_count: int, path_b_total: float, path_b_samples: list<string>,
     *     reduced_count: int,
     *     matched_count: int,
     *     ambiguous_ref_count: int,
     *     unreducible: array{count: int, sample: list<array{doc_number: ?string, our_os: float, legacy_os: float}>},
     * }
     */
    public function plan(string $batch): array
    {
        [$osvalueByLocalDocId, $ambiguousRefCount] = $this->resolveLegacyOutstanding();

        $prefix = (string) Setting::get('pay_prefix', 'PAY');
        $padding = (int) Setting::get('number_padding', 4);

        $last = DB::table('payments')->orderByDesc('id')->value('reference');
        $seq = $last ? (int) Str::afterLast($last, '-') + 1 : 1;

        $docIds = array_keys($osvalueByLocalDocId);

        $docs = collect();
        $allocByDoc = [];
        $creditByDoc = [];
        $woByDoc = [];

        foreach (collect($docIds)->chunk(20000) as $chunk) {
            $ids = $chunk->all();

            $docs = $docs->merge(
                Document::query()
                    ->whereIn('id', $ids)
                    ->when($this->excludeCustomerId, fn ($q) => $q->where('customer_id', '!=', $this->excludeCustomerId))
                    ->get(['id', 'customer_id', 'doc_number', 'doc_date', 'total_value'])
            );

            $allocByDoc += DB::table('payment_allocations')
                ->whereIn('document_id', $ids)
                ->whereNull('deleted_at')
                ->groupBy('document_id')
                ->selectRaw('document_id, SUM(allocated_amount) as s')
                ->pluck('s', 'document_id')
                ->all();

            $creditByDoc += DB::table('credit_allocations')
                ->whereIn('invoice_id', $ids)
                ->whereNull('deleted_at')
                ->groupBy('invoice_id')
                ->selectRaw('invoice_id, SUM(amount) as s')
                ->pluck('s', 'invoice_id')
                ->all();

            $woByDoc += DB::table('write_offs')
                ->whereIn('document_id', $ids)
                ->whereNull('deleted_at')
                ->groupBy('document_id')
                ->selectRaw('document_id, SUM(amount) as s')
                ->pluck('s', 'document_id')
                ->all();
        }

        $paymentRows = [];
        $pendingAllocations = [];
        $writeOffRows = [];
        $allocationReductions = [];
        $allocationDeletions = [];
        $exhaustPaymentIds = [];

        $pathACount = 0;
        $pathATotal = 0.0;
        $pathASamples = [];
        $pathBCount = 0;
        $pathBTotal = 0.0;
        $pathBSamples = [];
        $reducedCount = 0;
        $matchedCount = 0;
        $unreducible = ['count' => 0, 'sample' => []];

        foreach ($docs as $doc) {
            $legacyOs = $osvalueByLocalDocId[$doc->id];
            $allocated = round((float) ($allocByDoc[$doc->id] ?? 0), 2);
            $credited = round((float) ($creditByDoc[$doc->id] ?? 0), 2);
            $writtenOff = round((float) ($woByDoc[$doc->id] ?? 0), 2);
            $ourOs = round((float) $doc->total_value - $allocated - $credited - $writtenOff, 2);
            $delta = round($ourOs - $legacyOs, 2);
            $ts = ($doc->doc_date?->toDateString()) ?? now()->toDateString();

            if (abs($delta) <= self::TOLERANCE) {
                $matchedCount++;

                continue;
            }

            if ($delta > self::TOLERANCE) {
                if ($allocated <= 0.001) {
                    $reference = sprintf('%s-%s', $prefix, str_pad((string) $seq, $padding, '0', STR_PAD_LEFT));
                    $seq++;

                    $paymentRows[] = [
                        'customer_id' => $doc->customer_id,
                        'payment_method_id' => null,
                        'source_type' => 'cash',
                        'reference' => $reference,
                        'payment_reference' => null,
                        'amount' => $delta,
                        'is_exhausted' => false,
                        'payment_date' => $ts,
                        'notes' => sprintf(self::TAG_TEMPLATE, $batch),
                        'reconciliation_batch' => $batch,
                        'created_by' => $this->userId,
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ];

                    $pendingAllocations[$reference] = [
                        'document_id' => $doc->id,
                        'allocated_amount' => $delta,
                        'ts' => $ts,
                    ];

                    $pathACount++;
                    $pathATotal = round($pathATotal + $delta, 2);
                    if (count($pathASamples) < 10) {
                        $pathASamples[] = $doc->doc_number;
                    }
                } else {
                    $writeOffRows[] = [
                        'document_id' => $doc->id,
                        'amount' => $delta,
                        'reason' => sprintf(self::TAG_TEMPLATE, $batch),
                        'written_off_at' => $ts,
                        'written_off_by' => $this->userId,
                        'legacy_uid' => null,
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ];

                    $pathBCount++;
                    $pathBTotal = round($pathBTotal + $delta, 2);
                    if (count($pathBSamples) < 10) {
                        $pathBSamples[] = $doc->doc_number;
                    }
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
                        'our_os' => $ourOs,
                        'legacy_os' => $legacyOs,
                    ];
                }

                continue;
            }

            $reducedCount++;
        }

        return [
            'batch' => $batch,
            'payment_rows' => $paymentRows,
            'pending_allocations' => $pendingAllocations,
            'write_off_rows' => $writeOffRows,
            'allocation_reductions' => $allocationReductions,
            'allocation_deletions' => $allocationDeletions,
            'exhaust_payment_ids' => array_keys($exhaustPaymentIds),
            'path_a_count' => $pathACount,
            'path_a_total' => $pathATotal,
            'path_a_samples' => $pathASamples,
            'path_b_count' => $pathBCount,
            'path_b_total' => $pathBTotal,
            'path_b_samples' => $pathBSamples,
            'reduced_count' => $reducedCount,
            'matched_count' => $matchedCount,
            'ambiguous_ref_count' => $ambiguousRefCount,
            'unreducible' => $unreducible,
        ];
    }

    /**
     * @param  array{payment_rows: array, write_off_rows: array, allocation_reductions: array, allocation_deletions: array}  $plan
     */
    public function isEmpty(array $plan): bool
    {
        return $plan['payment_rows'] === []
            && $plan['write_off_rows'] === []
            && $plan['allocation_reductions'] === []
            && $plan['allocation_deletions'] === [];
    }

    /**
     * @param  array{
     *     batch: string,
     *     payment_rows: array,
     *     pending_allocations: array<string, array{document_id: int, allocated_amount: float, ts: string}>,
     *     write_off_rows: array,
     *     allocation_reductions: array<int, array{id: int, new_amount: float}>,
     *     allocation_deletions: array<int, int>,
     *     exhaust_payment_ids: array<int, int>,
     * }  $plan
     */
    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan) {
            $batch = $plan['batch'];

            $pathADocIds = array_column($plan['pending_allocations'], 'document_id');

            if ($pathADocIds !== []) {
                DB::table('payment_allocations')
                    ->whereIn('document_id', $pathADocIds)
                    ->whereNotNull('deleted_at')
                    ->delete();
            }

            foreach (array_chunk($plan['payment_rows'], 1000) as $chunk) {
                DB::table('payments')->insert($chunk);
            }

            if ($plan['pending_allocations'] !== []) {
                $idByRef = DB::table('payments')
                    ->where('reconciliation_batch', $batch)
                    ->whereIn('reference', array_keys($plan['pending_allocations']))
                    ->pluck('id', 'reference');

                $allocationRows = [];

                foreach ($plan['pending_allocations'] as $ref => $entry) {
                    $allocationRows[] = [
                        'payment_id' => $idByRef[$ref],
                        'document_id' => $entry['document_id'],
                        'allocated_amount' => $entry['allocated_amount'],
                        'created_at' => $entry['ts'],
                        'updated_at' => $entry['ts'],
                    ];
                }

                foreach (array_chunk($allocationRows, 1000) as $chunk) {
                    DB::table('payment_allocations')->insert($chunk);
                }
            }

            foreach (array_chunk($plan['write_off_rows'], 1000) as $chunk) {
                DB::table('write_offs')->insert($chunk);
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
     * Undoes only the rows this run's apply() inserted: soft-deletes the
     * synthetic payments tagged with $batch, their non-trashed allocations, and
     * the write-offs it created. It does NOT restore allocations shrunk or
     * soft-deleted by the over-applied reduce path, nor un-exhaust payments it
     * marked exhausted.
     *
     * @return array{payments: int, allocations: int, write_offs: int}
     */
    public function revert(string $batch): array
    {
        return DB::transaction(function () use ($batch) {
            $paymentIds = DB::table('payments')
                ->where('reconciliation_batch', $batch)
                ->whereNull('deleted_at')
                ->pluck('id');

            $allocationsDeleted = 0;

            foreach ($paymentIds->chunk(1000) as $chunk) {
                $allocationsDeleted += DB::table('payment_allocations')
                    ->whereIn('payment_id', $chunk->all())
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }

            foreach ($paymentIds->chunk(1000) as $chunk) {
                DB::table('payments')
                    ->whereIn('id', $chunk->all())
                    ->whereNull('deleted_at')
                    ->update(['deleted_at' => now(), 'updated_at' => now()]);
            }

            $writeOffsDeleted = DB::table('write_offs')
                ->where('reason', 'like', sprintf('[LEGACY-RECON %s]', $batch).'%')
                ->whereNull('deleted_at')
                ->update(['deleted_at' => now(), 'updated_at' => now()]);

            return [
                'payments' => $paymentIds->count(),
                'allocations' => $allocationsDeleted,
                'write_offs' => $writeOffsDeleted,
            ];
        });
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
