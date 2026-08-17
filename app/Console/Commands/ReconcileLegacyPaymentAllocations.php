<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('payments:reconcile-legacy-allocations {--dry-run : Preview the changes without writing them}')]
#[Description('Reconciles migrated legacy payments against migrated invoices. Legacy never recorded which payment paid which invoice — only each invoice\'s current outstanding balance (AccountEntries.Osvalue). This command computes each invoice\'s true target-settled amount from that legacy fact, then funds oldest-invoice-first from that customer\'s oldest migrated payments (an approximation of which payment funded which invoice, not a reconstruction of real history — but the resulting outstanding-per-invoice figure is exact, since it is read directly from legacy, not guessed). Requires the `legacy` connection to still be configured and reachable.')]
class ReconcileLegacyPaymentAllocations extends Command
{
    public function handle(): int
    {
        [$osvalueByDocumentLegacyUid, $ambiguousRefs] = $this->buildLegacyTargetMap();

        $targetDocuments = Document::query()
            ->invoices()
            ->whereNotNull('legacy_uid')
            ->whereIn('legacy_uid', array_keys($osvalueByDocumentLegacyUid))
            ->get()
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

        $customerIds = $targets->pluck('document.customer_id')->unique()->values();

        $paymentsByCustomer = Payment::query()
            ->whereNotNull('legacy_uid')
            ->whereIn('customer_id', $customerIds)
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

        $toSettle = $targets->filter(fn (array $t) => $t['is_settled'] && ! $t['document']->is_settled)->values();
        $toUnsettle = $targets->filter(fn (array $t) => ! $t['is_settled'] && $t['document']->is_settled)->values();

        if ($toSettle->isNotEmpty()) {
            $this->table(
                ['Doc Number', 'Target Settled Amount'],
                $toSettle->map(fn (array $t) => [
                    $t['document']->doc_number,
                    number_format($t['target_settled'], 2),
                ])->all(),
            );
        }

        if (! empty($shortfalls)) {
            $this->table(
                ['Doc Number', 'Customer ID', 'Shortfall'],
                collect($shortfalls)->map(fn (array $s) => [
                    $s['doc_number'],
                    $s['customer_id'],
                    number_format($s['shortfall'], 2),
                ])->all(),
            );
        }

        if (! empty($ambiguousRefs)) {
            $this->table(
                ['Legacy Ref', 'Matched Documents Rows'],
                collect($ambiguousRefs)->map(fn (int $count, string $ref) => [$ref, $count])->all(),
            );
        }

        if ($toSettle->isEmpty() && $toUnsettle->isEmpty() && empty($allocationRows) && empty($shortfalls) && empty($ambiguousRefs)) {
            $this->info('Nothing to reconcile.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%d invoice(s) to settle, %d shortfall(s), %d excluded (ambiguous ref), %d allocation row(s) to write.',
            $toSettle->count(),
            count($shortfalls),
            count($ambiguousRefs),
            count($allocationRows),
        ));

        if ($this->option('dry-run')) {
            $this->comment('Dry run — no changes written.');

            return self::SUCCESS;
        }

        if (! $this->confirm('Apply these changes?', true)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($allocationRows, $toSettle, $toUnsettle) {
            $migratedPaymentIds = Payment::whereNotNull('legacy_uid')->pluck('id');

            PaymentAllocation::whereIn('payment_id', $migratedPaymentIds)->forceDelete();

            foreach (array_chunk($allocationRows, 1000) as $chunk) {
                PaymentAllocation::insert($chunk);
            }

            if ($toSettle->isNotEmpty()) {
                Document::whereIn('id', $toSettle->pluck('document.id'))->update(['is_settled' => true]);
            }

            if ($toUnsettle->isNotEmpty()) {
                Document::whereIn('id', $toUnsettle->pluck('document.id'))->update(['is_settled' => false]);
            }
        });

        $this->info(sprintf(
            'Reconciliation complete. %d invoice(s) settled, %d allocation row(s) written, %d shortfall(s) remain.',
            $toSettle->count(),
            count($allocationRows),
            count($shortfalls),
        ));

        return self::SUCCESS;
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
