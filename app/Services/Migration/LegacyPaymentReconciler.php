<?php

namespace App\Services\Migration;

use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links migrated payments to migrated invoices and derives each invoice's settled
 * status. Legacy never recorded which payment paid which invoice — only each
 * invoice's current outstanding balance (AccountEntries.Osvalue) — so this computes
 * each invoice's true target-settled amount from that legacy fact, then funds
 * oldest-invoice-first from that customer's oldest migrated payments. That's an
 * approximation of *which* payment funded *which* invoice (not a reconstruction of
 * real history), but the resulting outstanding-per-invoice figure is exact, since
 * it's read directly from legacy, not guessed.
 *
 * Kept independent of `App\Services\PaymentAllocator`, which optimizes for "settle
 * oldest invoices fully, whatever it takes" — correct for a live user allocating a
 * *new* payment, but wrong here: it would fully settle an invoice legacy shows as
 * still partially outstanding, and starve another.
 *
 * Split into plan()/apply() so both the CLI command (preview, confirm, dry-run) and
 * the migration job (apply immediately, same request-scoped legacy connection) can
 * share this logic without either owning console I/O.
 *
 * Local Document/Payment Eloquent collections are processed in customer batches (see
 * CUSTOMER_BATCH_SIZE) — the same reason BulkEntityMapper implementations chunk
 * instead of loading a whole table at once (EntityMapper's own docblock cites legacy
 * tables here reaching millions of rows), and why plain Eloquent models (this app's
 * `documents`/`payments`) are far heavier per row than the raw legacy `stdClass` rows
 * `buildLegacyTargetMap()` reads. Rows can't be chunked independently here — correctly
 * funding invoices oldest-first requires seeing *all* of one customer's invoices and
 * payments together — so the batch boundary is drawn at the customer level.
 *
 * `buildLegacyTargetMap()` itself stays a single global pass, not customer-scoped:
 * scoping it by customer was tried and reverted — a small number of legacy
 * `AccountEntries.Custid` values (52 out of 52,580, confirmed against live data) don't
 * match the `Documents.Acctuid` of the invoice they actually reference, so filtering
 * either side by customer silently drops real matches. The raw rows it reads are
 * lightweight `stdClass` objects, not Eloquent models, so loading them in one pass is
 * cheap relative to the Eloquent collections this class batches.
 */
class LegacyPaymentReconciler
{
    private const int CUSTOMER_BATCH_SIZE = 500;

    /**
     * @return array{
     *     to_settle: Collection<int, array{document_id: int, doc_number: ?string, target_settled: float}>,
     *     to_unsettle: Collection<int, int>,
     *     allocation_rows: array<int, array{payment_id: int, document_id: int, allocated_amount: float, created_at: Carbon, updated_at: Carbon}>,
     *     shortfalls: array<int, array{customer_id: int, doc_number: ?string, shortfall: float}>,
     *     ambiguous_refs: array<string, int>,
     * }
     */
    public function plan(): array
    {
        [$osvalueByDocumentLegacyUid, $ambiguousRefs] = $this->buildLegacyTargetMap();

        $customerIds = Document::query()
            ->invoices()
            ->whereNotNull('legacy_uid')
            ->distinct()
            ->pluck('customer_id');

        $toSettle = collect();
        $toUnsettle = collect();
        $allocationRows = [];
        $shortfalls = [];

        foreach ($customerIds->chunk(self::CUSTOMER_BATCH_SIZE) as $batch) {
            $batchPlan = $this->planForCustomerBatch($batch->values(), $osvalueByDocumentLegacyUid);

            $toSettle = $toSettle->concat($batchPlan['to_settle']);
            $toUnsettle = $toUnsettle->concat($batchPlan['to_unsettle']);
            array_push($allocationRows, ...$batchPlan['allocation_rows']);
            array_push($shortfalls, ...$batchPlan['shortfalls']);
        }

        return [
            'to_settle' => $toSettle,
            'to_unsettle' => $toUnsettle,
            'allocation_rows' => $allocationRows,
            'shortfalls' => $shortfalls,
            'ambiguous_refs' => $ambiguousRefs,
        ];
    }

    public function isEmpty(array $plan): bool
    {
        return $plan['to_settle']->isEmpty()
            && $plan['to_unsettle']->isEmpty()
            && empty($plan['allocation_rows'])
            && empty($plan['shortfalls'])
            && empty($plan['ambiguous_refs']);
    }

    /**
     * @param  array{to_settle: Collection, to_unsettle: Collection, allocation_rows: array, shortfalls: array, ambiguous_refs: array}  $plan
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
     * @param  Collection<int, int>  $customerIds
     * @param  array<int, float>  $osvalueByDocumentLegacyUid
     * @return array{
     *     to_settle: Collection<int, array{document_id: int, doc_number: ?string, target_settled: float}>,
     *     to_unsettle: Collection<int, int>,
     *     allocation_rows: array<int, array{payment_id: int, document_id: int, allocated_amount: float, created_at: Carbon, updated_at: Carbon}>,
     *     shortfalls: array<int, array{customer_id: int, doc_number: ?string, shortfall: float}>,
     * }
     */
    private function planForCustomerBatch(Collection $customerIds, array $osvalueByDocumentLegacyUid): array
    {
        $targetDocuments = Document::query()
            ->invoices()
            ->whereIn('customer_id', $customerIds)
            ->whereNotNull('legacy_uid')
            ->get()
            ->filter(fn (Document $document) => array_key_exists($document->legacy_uid, $osvalueByDocumentLegacyUid))
            ->keyBy('legacy_uid');

        $targets = $targetDocuments->map(function (Document $document) use ($osvalueByDocumentLegacyUid) {
            $osvalue = $osvalueByDocumentLegacyUid[$document->legacy_uid];
            $totalValue = (float) $document->total_value;

            return [
                'document' => $document,
                'osvalue' => $osvalue,
                'target_settled' => min(max($totalValue - $osvalue, 0.0), $totalValue),
                'is_settled' => $osvalue <= 0.001,
            ];
        });

        $paymentsByCustomer = Payment::query()
            ->whereIn('customer_id', $customerIds)
            ->whereNotNull('legacy_uid')
            ->orderBy('payment_date')
            ->get()
            ->groupBy('customer_id')
            ->map(fn ($payments) => $payments->map(fn (Payment $payment) => [
                'payment' => $payment,
                'remaining' => (float) $payment->amount,
            ])->all());

        $allocationRows = [];
        $shortfalls = [];

        foreach ($targets->groupBy('document.customer_id') as $customerId => $customerTargets) {
            $customerPayments = $paymentsByCustomer[$customerId] ?? [];

            foreach ($customerTargets->sortBy('document.doc_date') as $target) {
                $needed = $target['target_settled'];

                foreach ($customerPayments as $index => $entry) {
                    if ($needed <= 0.001) {
                        break;
                    }

                    if ($entry['remaining'] <= 0.001) {
                        continue;
                    }

                    $draw = min($needed, $entry['remaining']);

                    $allocationRows[] = [
                        'payment_id' => $entry['payment']->id,
                        'document_id' => $target['document']->id,
                        'allocated_amount' => round($draw, 2),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];

                    $customerPayments[$index]['remaining'] -= $draw;
                    $needed -= $draw;
                }

                if ($needed > 0.001) {
                    $shortfalls[] = [
                        'customer_id' => $customerId,
                        'doc_number' => $target['document']->doc_number,
                        'shortfall' => round($needed, 2),
                    ];
                }
            }

            $paymentsByCustomer[$customerId] = $customerPayments;
        }

        return [
            'to_settle' => $targets
                ->filter(fn (array $t) => $t['is_settled'] && ! $t['document']->is_settled)
                ->map(fn (array $t) => [
                    'document_id' => $t['document']->id,
                    'doc_number' => $t['document']->doc_number,
                    'target_settled' => $t['target_settled'],
                ])
                ->values(),
            'to_unsettle' => $targets
                ->filter(fn (array $t) => ! $t['is_settled'] && $t['document']->is_settled)
                ->map(fn (array $t) => $t['document']->id)
                ->values(),
            'allocation_rows' => $allocationRows,
            'shortfalls' => $shortfalls,
        ];
    }

    /**
     * Builds the map of documents.legacy_uid => legacy outstanding balance (Osvalue),
     * restricted to Invno/Ref matches that resolve to exactly one legacy Documents row.
     * A single global pass — see the class docblock for why this isn't customer-scoped.
     *
     * @return array{0: array<int, float>, 1: array<string, int>}
     */
    private function buildLegacyTargetMap(): array
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

        $osvalueByDocumentLegacyUid = [];
        $ambiguousRefs = [];

        foreach ($entries as $entry) {
            $ref = trim((string) $entry->invno);
            $matches = $documentsByRef->get($ref);

            if ($matches === null || $matches->count() !== 1) {
                $ambiguousRefs[$ref] = ($ambiguousRefs[$ref] ?? 0) + ($matches?->count() ?? 0);

                continue;
            }

            $osvalueByDocumentLegacyUid[$matches->first()->uid] = (float) $entry->osvalue;
        }

        return [$osvalueByDocumentLegacyUid, $ambiguousRefs];
    }
}
