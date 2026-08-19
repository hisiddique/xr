<?php

namespace App\Services\Migration;

use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links migrated payments to migrated invoices using legacy's real, row-level
 * allocation table (`AccountBatchItems`) — one row per (payment, invoice) pairing,
 * carrying the exact amount that payment applied to that invoice. This is historical
 * fact, not a guess: `cshuid` identifies the payment (matches `payments.legacy_uid`,
 * the same `AccountEntries.uid` `PaymentMapper` reads), `txnref` identifies the
 * invoice (matches `Documents.ref` where `rtype='i'`), and `paymt` — scaled by that
 * row's `AccountPostTypes.entryvalue` sign flag, the same way the legacy app's own
 * `sp_CustSupp_Transactions_Details` proc displays it — is the amount applied.
 *
 * Where that link can't be resolved (no batch-item row for a payment, a `txnref`
 * that doesn't map to exactly one invoice, a `cshuid` with no matching migrated
 * payment, or a resolved sum that doesn't match the payment's own value or the
 * invoice's legacy target-settled amount), this reports the gap instead of
 * papering over it with a guess — there is no reliable fallback for a payment
 * that legacy itself never fully accounted for.
 *
 * `is_settled` is derived independently from `AccountEntries.Osvalue` (each
 * invoice's own outstanding balance) — exact regardless of allocation resolution,
 * so it is never influenced by how well the allocation rows above resolved.
 */
class LegacyPaymentReconciler
{
    private const float TOLERANCE = 0.01;

    private const int REPORT_SAMPLE_LIMIT = 100;

    /**
     * @return array{
     *     to_settle: Collection<int, array{document_id: int, doc_number: ?string, target_settled: float}>,
     *     to_unsettle: Collection<int, int>,
     *     allocation_rows: array<int, array{payment_id: int, document_id: int, allocated_amount: float, created_at: Carbon, updated_at: Carbon}>,
     *     ambiguous_refs: array<string, int>,
     *     unallocated_payments: array{count: int, sample: array<int, array{legacy_uid: int, reference: ?string, amount: float}>},
     *     partially_allocated_payments: array{count: int, sample: array<int, array{legacy_uid: int, reference: ?string, amount: float, allocated: float}>},
     *     over_allocated_payments: array{count: int, sample: array<int, array{legacy_uid: int, reference: ?string, amount: float, allocated: float}>},
     *     orphaned_batch_items: array{count: int, sample: array<int, int>},
     *     invoice_target_mismatches: array{count: int, sample: array<int, array{doc_number: ?string, target_settled: float, allocated: float}>},
     * }
     */
    public function plan(): array
    {
        [$osvalueByDocumentLegacyUid, $ambiguousRefs, $documentIdByRef] = $this->buildInvoiceRefMaps();

        $paymentIdByLegacyUid = Payment::query()->whereNotNull('legacy_uid')->pluck('id', 'legacy_uid')->all();
        $paymentAmountByLegacyUid = Payment::query()->whereNotNull('legacy_uid')->pluck('amount', 'legacy_uid')->all();
        $paymentReferenceByLegacyUid = Payment::query()->whereNotNull('legacy_uid')->pluck('payment_reference', 'legacy_uid')->all();

        $entryvalueByPosttype = DB::connection('legacy')->table('AccountPostTypes')->pluck('entryvalue', 'uid')->all();

        $allocationRows = [];
        $allocatedByPaymentLegacyUid = [];
        $allocatedByDocumentId = [];
        $orphanedCshuids = [];

        DB::connection('legacy')->table('AccountBatchItems')
            ->whereNotNull('cshuid')
            ->where('cshuid', '!=', 0)
            ->select(['cshuid', 'txnabbr', 'txnref', 'paymt', 'posttype'])
            ->orderBy('cshuid')
            ->each(function ($row) use (
                &$allocationRows, &$allocatedByPaymentLegacyUid, &$allocatedByDocumentId, &$orphanedCshuids,
                $paymentIdByLegacyUid, $documentIdByRef, $entryvalueByPosttype,
            ) {
                if (trim((string) $row->txnabbr) !== 'INV-') {
                    return;
                }

                $paymentId = $paymentIdByLegacyUid[$row->cshuid] ?? null;

                if ($paymentId === null) {
                    $orphanedCshuids[$row->cshuid] = true;

                    return;
                }

                $documentId = $documentIdByRef[trim((string) $row->txnref)] ?? null;

                if ($documentId === null) {
                    return;
                }

                $multiplier = 1.0;
                if ($row->posttype !== null && (float) ($entryvalueByPosttype[$row->posttype] ?? 0) !== 0.0) {
                    $multiplier = (float) $entryvalueByPosttype[$row->posttype];
                }

                $amount = round((float) $row->paymt * $multiplier, 2);

                if ($amount <= 0) {
                    return;
                }

                // A payment can carry more than one AccountBatchItems row against the
                // same invoice (e.g. two lines posted in the same batch) — the target
                // table's (payment_id, document_id) unique constraint means those must
                // collapse into one summed row, not one insert per legacy line.
                $key = $paymentId.'-'.$documentId;

                if (isset($allocationRows[$key])) {
                    $allocationRows[$key]['allocated_amount'] = round($allocationRows[$key]['allocated_amount'] + $amount, 2);
                } else {
                    $allocationRows[$key] = [
                        'payment_id' => $paymentId,
                        'document_id' => $documentId,
                        'allocated_amount' => $amount,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }

                $allocatedByPaymentLegacyUid[$row->cshuid] = ($allocatedByPaymentLegacyUid[$row->cshuid] ?? 0) + $amount;
                $allocatedByDocumentId[$documentId] = ($allocatedByDocumentId[$documentId] ?? 0) + $amount;
            });

        $allocationRows = array_values($allocationRows);

        $unallocated = ['count' => 0, 'sample' => []];
        $partial = ['count' => 0, 'sample' => []];
        $over = ['count' => 0, 'sample' => []];

        foreach ($paymentIdByLegacyUid as $legacyUid => $paymentId) {
            $expected = (float) $paymentAmountByLegacyUid[$legacyUid];
            $allocated = $allocatedByPaymentLegacyUid[$legacyUid] ?? 0.0;

            if ($allocated <= self::TOLERANCE) {
                $bucket = &$unallocated;
                $row = ['legacy_uid' => $legacyUid, 'reference' => $paymentReferenceByLegacyUid[$legacyUid] ?? null, 'amount' => $expected];
            } elseif ($allocated > $expected + self::TOLERANCE) {
                $bucket = &$over;
                $row = ['legacy_uid' => $legacyUid, 'reference' => $paymentReferenceByLegacyUid[$legacyUid] ?? null, 'amount' => $expected, 'allocated' => round($allocated, 2)];
            } elseif (abs($allocated - $expected) > self::TOLERANCE) {
                $bucket = &$partial;
                $row = ['legacy_uid' => $legacyUid, 'reference' => $paymentReferenceByLegacyUid[$legacyUid] ?? null, 'amount' => $expected, 'allocated' => round($allocated, 2)];
            } else {
                continue;
            }

            $bucket['count']++;
            if (count($bucket['sample']) < self::REPORT_SAMPLE_LIMIT) {
                $bucket['sample'][] = $row;
            }
            unset($bucket);
        }

        [$toSettle, $toUnsettle] = $this->buildSettlementDiff($osvalueByDocumentLegacyUid);

        $invoiceMismatches = ['count' => 0, 'sample' => []];
        $documentsByLegacyUid = Document::query()->invoices()->whereNotNull('legacy_uid')->get(['id', 'legacy_uid', 'doc_number', 'total_value'])->keyBy('legacy_uid');

        foreach ($osvalueByDocumentLegacyUid as $legacyUid => $osvalue) {
            $document = $documentsByLegacyUid[$legacyUid] ?? null;

            if ($document === null) {
                continue;
            }

            $targetSettled = min(max((float) $document->total_value - $osvalue, 0.0), (float) $document->total_value);
            $allocated = $allocatedByDocumentId[$document->id] ?? 0.0;

            if (abs($allocated - $targetSettled) > self::TOLERANCE) {
                $invoiceMismatches['count']++;
                if (count($invoiceMismatches['sample']) < self::REPORT_SAMPLE_LIMIT) {
                    $invoiceMismatches['sample'][] = [
                        'doc_number' => $document->doc_number,
                        'target_settled' => round($targetSettled, 2),
                        'allocated' => round($allocated, 2),
                    ];
                }
            }
        }

        $orphanedKeys = array_keys($orphanedCshuids);

        return [
            'to_settle' => $toSettle,
            'to_unsettle' => $toUnsettle,
            'allocation_rows' => $allocationRows,
            'ambiguous_refs' => $ambiguousRefs,
            'unallocated_payments' => $unallocated,
            'partially_allocated_payments' => $partial,
            'over_allocated_payments' => $over,
            'orphaned_batch_items' => [
                'count' => count($orphanedKeys),
                'sample' => array_slice($orphanedKeys, 0, self::REPORT_SAMPLE_LIMIT),
            ],
            'invoice_target_mismatches' => $invoiceMismatches,
        ];
    }

    public function isEmpty(array $plan): bool
    {
        return $plan['to_settle']->isEmpty()
            && $plan['to_unsettle']->isEmpty()
            && empty($plan['allocation_rows'])
            && empty($plan['ambiguous_refs']);
    }

    /**
     * @param  array{to_settle: Collection, to_unsettle: Collection, allocation_rows: array}  $plan
     */
    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan) {
            // Subquery instead of pulling every migrated payment id into PHP for an
            // IN-list: at real scale that list itself risks the same placeholder cap.
            PaymentAllocation::whereIn('payment_id', Payment::whereNotNull('legacy_uid')->select('id'))
                ->forceDelete();

            foreach (array_chunk($plan['allocation_rows'], 1000) as $chunk) {
                PaymentAllocation::insert($chunk);
            }

            foreach ($plan['to_settle']->pluck('document_id')->chunk(1000) as $ids) {
                Document::whereIn('id', $ids)->update(['is_settled' => true]);
            }

            foreach ($plan['to_unsettle']->chunk(1000) as $ids) {
                Document::whereIn('id', $ids)->update(['is_settled' => false]);
            }
        });
    }

    /**
     * @param  array<int, float>  $osvalueByDocumentLegacyUid
     * @return array{0: Collection, 1: Collection}
     */
    private function buildSettlementDiff(array $osvalueByDocumentLegacyUid): array
    {
        $targetDocuments = Document::query()
            ->invoices()
            ->whereNotNull('legacy_uid')
            ->get(['id', 'legacy_uid', 'doc_number', 'total_value', 'is_settled'])
            ->filter(fn (Document $document) => array_key_exists($document->legacy_uid, $osvalueByDocumentLegacyUid));

        $toSettle = collect();
        $toUnsettle = collect();

        foreach ($targetDocuments as $document) {
            $osvalue = $osvalueByDocumentLegacyUid[$document->legacy_uid];
            $totalValue = (float) $document->total_value;
            $targetSettled = min(max($totalValue - $osvalue, 0.0), $totalValue);
            $isSettled = $osvalue <= 0.001;

            if ($isSettled && ! $document->is_settled) {
                $toSettle->push([
                    'document_id' => $document->id,
                    'doc_number' => $document->doc_number,
                    'target_settled' => $targetSettled,
                ]);
            } elseif (! $isSettled && $document->is_settled) {
                $toUnsettle->push($document->id);
            }
        }

        return [$toSettle, $toUnsettle];
    }

    /**
     * Builds the map of documents.legacy_uid => legacy outstanding balance (Osvalue),
     * restricted to Invno/Ref matches that resolve to exactly one legacy Documents row,
     * plus that same ref => local document id map reused for AccountBatchItems.txnref
     * resolution (same underlying `Documents` rows, so one ambiguity report covers both).
     *
     * @return array{0: array<int, float>, 1: array<string, int>, 2: array<string, int>}
     */
    private function buildInvoiceRefMaps(): array
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
            ->groupBy(fn ($row) => trim((string) $row->ref));

        $localIdByLegacyUid = Document::query()->invoices()->whereNotNull('legacy_uid')->pluck('id', 'legacy_uid')->all();

        $osvalueByDocumentLegacyUid = [];
        $documentIdByRef = [];
        $ambiguousRefs = [];

        foreach ($documentsByRef as $ref => $matches) {
            if ($matches->count() !== 1) {
                $ambiguousRefs[$ref] = $matches->count();

                continue;
            }

            $localId = $localIdByLegacyUid[$matches->first()->uid] ?? null;

            if ($localId !== null) {
                $documentIdByRef[$ref] = $localId;
            }
        }

        foreach ($entries as $entry) {
            $ref = trim((string) $entry->invno);
            $matches = $documentsByRef->get($ref);

            if ($matches === null) {
                $ambiguousRefs[$ref] = 0;

                continue;
            }

            if ($matches->count() !== 1) {
                continue;
            }

            $osvalueByDocumentLegacyUid[$matches->first()->uid] = (float) $entry->osvalue;
        }

        return [$osvalueByDocumentLegacyUid, $ambiguousRefs, $documentIdByRef];
    }
}
