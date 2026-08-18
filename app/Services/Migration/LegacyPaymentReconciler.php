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
 */
class LegacyPaymentReconciler
{
    /**
     * @return array{
     *     to_settle: Collection<int, array{document: Document, target_settled: float}>,
     *     to_unsettle: Collection<int, Document>,
     *     allocation_rows: array<int, array{payment_id: int, document_id: int, allocated_amount: float, created_at: Carbon, updated_at: Carbon}>,
     *     shortfalls: array<int, array{customer_id: int, doc_number: ?string, shortfall: float}>,
     *     ambiguous_refs: array<string, int>,
     * }
     */
    public function plan(): array
    {
        [$osvalueByDocumentLegacyUid, $ambiguousRefs] = $this->buildLegacyTargetMap();

        // Filtered in PHP against the preloaded map rather than whereIn('legacy_uid', $keys):
        // that list can hold tens of thousands of entries, which risks MySQL's 65535
        // prepared-statement placeholder cap (see MigrationRunner::applyBulkChunk()'s
        // identical concern) — the same reason every mapper here preloads maps instead
        // of building large IN clauses.
        $targetDocuments = Document::query()
            ->invoices()
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

        $customerIds = $targets->pluck('document.customer_id')->unique()->flip();

        $paymentsByCustomer = Payment::query()
            ->whereNotNull('legacy_uid')
            ->orderBy('payment_date')
            ->get()
            ->filter(fn (Payment $payment) => $customerIds->has($payment->customer_id))
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
            'to_settle' => $targets->filter(fn (array $t) => $t['is_settled'] && ! $t['document']->is_settled)->values(),
            'to_unsettle' => $targets->filter(fn (array $t) => ! $t['is_settled'] && $t['document']->is_settled)->map(fn (array $t) => $t['document'])->values(),
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
            $migratedPaymentIds = Payment::whereNotNull('legacy_uid')->pluck('id');

            PaymentAllocation::whereIn('payment_id', $migratedPaymentIds)->forceDelete();

            foreach (array_chunk($plan['allocation_rows'], 1000) as $chunk) {
                PaymentAllocation::insert($chunk);
            }

            if ($plan['to_settle']->isNotEmpty()) {
                Document::whereIn('id', $plan['to_settle']->pluck('document.id'))->update(['is_settled' => true]);
            }

            if ($plan['to_unsettle']->isNotEmpty()) {
                Document::whereIn('id', $plan['to_unsettle']->pluck('id'))->update(['is_settled' => false]);
            }
        });
    }

    /**
     * Builds the map of documents.legacy_uid => legacy outstanding balance (Osvalue),
     * restricted to Invno/Ref matches that resolve to exactly one legacy Documents row.
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
