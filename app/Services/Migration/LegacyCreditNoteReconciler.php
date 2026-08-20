<?php

namespace App\Services\Migration;

use App\Models\CreditAllocation;
use App\Models\Document;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Links migrated credit notes to the invoice they were raised against, using the
 * explicit source reference legacy stores on the credit note's own `Documents` row —
 * `srcabbr`/`srcref` (e.g. `srcabbr='INV-'`, `srcref='772988'`) — not a guess. This is
 * separate from `LegacyPaymentReconciler` because it reads a different legacy fact and
 * populates a different target: `documents.credited_invoice_id` (the reference the
 * live app's own credit-note create/edit pages set and display) and a `CreditAllocation`
 * row (payment_id null — an unpaid, direct credit-to-invoice application, the same
 * shape `PaymentAllocator::fundFromCreditNotes()` never produces since that always sets
 * payment_id).
 *
 * Deliberately does not touch `documents.is_settled` for the credited invoice — legacy's
 * `AccountEntries.Osvalue` (read by `LegacyPaymentReconciler`) already reflects this
 * credit note's effect on that invoice's balance; adding it again here would double-count.
 *
 * Where `srcref` is blank, points at something other than an invoice, or doesn't resolve
 * to exactly one invoice, this reports the gap rather than guessing which invoice it was.
 */
class LegacyCreditNoteReconciler
{
    private const int REPORT_SAMPLE_LIMIT = 100;

    /**
     * @return array{
     *     credited_invoice_updates: array<int, array{document_id: int, invoice_id: int}>,
     *     allocation_rows: array<int, array{credit_note_id: int, invoice_id: int, amount: float, created_at: Carbon, updated_at: Carbon}>,
     *     ambiguous_refs: array<string, int>,
     *     unresolved_credit_notes: array{count: int, sample: array<int, array{doc_number: ?string, reason: string}>},
     * }
     */
    public function plan(): array
    {
        $documentsByRef = DB::connection('legacy')->table('Documents')
            ->where('rtype', 'i')
            ->select(['uid', 'ref'])
            ->get()
            ->groupBy(fn ($row) => trim((string) $row->ref));

        $invoiceLocalIdByLegacyUid = Document::query()->invoices()->whereNotNull('legacy_uid')->pluck('id', 'legacy_uid')->all();

        $creditNotes = Document::query()
            ->creditNotes()
            ->whereNotNull('legacy_uid')
            ->get(['id', 'legacy_uid', 'doc_number', 'total_value', 'credited_invoice_id', 'doc_date']);

        $legacyRowsByUid = DB::connection('legacy')->table('Documents')
            ->where('rtype', 'r')
            ->whereIn('uid', $creditNotes->pluck('legacy_uid'))
            ->select(['uid', 'srcabbr', 'srcref'])
            ->get()
            ->keyBy('uid');

        $creditedInvoiceUpdates = [];
        $allocationRows = [];
        $ambiguousRefs = [];
        $unresolved = ['count' => 0, 'sample' => []];

        foreach ($creditNotes as $creditNote) {
            $legacyRow = $legacyRowsByUid->get($creditNote->legacy_uid);

            $reason = $this->resolve(
                $creditNote,
                $legacyRow,
                $documentsByRef,
                $invoiceLocalIdByLegacyUid,
                $creditedInvoiceUpdates,
                $allocationRows,
                $ambiguousRefs,
            );

            if ($reason !== null) {
                $unresolved['count']++;
                if (count($unresolved['sample']) < self::REPORT_SAMPLE_LIMIT) {
                    $unresolved['sample'][] = ['doc_number' => $creditNote->doc_number, 'reason' => $reason];
                }
            }
        }

        return [
            'credited_invoice_updates' => $creditedInvoiceUpdates,
            'allocation_rows' => $allocationRows,
            'ambiguous_refs' => $ambiguousRefs,
            'unresolved_credit_notes' => $unresolved,
        ];
    }

    /**
     * @param  array<string, Collection>  $documentsByRef
     * @param  array<int, int>  $invoiceLocalIdByLegacyUid
     * @param  array<int, array{document_id: int, invoice_id: int}>  &$creditedInvoiceUpdates
     * @param  array<int, array{credit_note_id: int, invoice_id: int, amount: float, created_at: Carbon, updated_at: Carbon}>  &$allocationRows
     * @param  array<string, int>  &$ambiguousRefs
     */
    private function resolve(
        Document $creditNote,
        mixed $legacyRow,
        $documentsByRef,
        array $invoiceLocalIdByLegacyUid,
        array &$creditedInvoiceUpdates,
        array &$allocationRows,
        array &$ambiguousRefs,
    ): ?string {
        if ($legacyRow === null) {
            return 'no matching legacy Documents row';
        }

        if (trim((string) $legacyRow->srcabbr) !== 'INV-') {
            return 'source reference is not an invoice';
        }

        $ref = trim((string) $legacyRow->srcref);

        if ($ref === '') {
            return 'no source reference recorded';
        }

        $matches = $documentsByRef->get($ref);

        if ($matches === null || $matches->count() !== 1) {
            $ambiguousRefs[$ref] = ($ambiguousRefs[$ref] ?? 0) + ($matches?->count() ?? 0);

            return $matches === null ? 'source invoice not found' : 'source reference matches multiple invoices';
        }

        $invoiceId = $invoiceLocalIdByLegacyUid[$matches->first()->uid] ?? null;

        if ($invoiceId === null) {
            return 'source invoice was not migrated';
        }

        if ($creditNote->credited_invoice_id !== $invoiceId) {
            $creditedInvoiceUpdates[] = ['document_id' => $creditNote->id, 'invoice_id' => $invoiceId];
        }

        $postedAt = $creditNote->doc_date ?? now();

        $allocationRows[] = [
            'credit_note_id' => $creditNote->id,
            'invoice_id' => $invoiceId,
            'amount' => (float) $creditNote->total_value,
            'created_at' => $postedAt,
            'updated_at' => $postedAt,
        ];

        return null;
    }

    public function isEmpty(array $plan): bool
    {
        return empty($plan['credited_invoice_updates'])
            && empty($plan['allocation_rows'])
            && empty($plan['ambiguous_refs']);
    }

    /**
     * @param  array{credited_invoice_updates: array, allocation_rows: array}  $plan
     */
    public function apply(array $plan): void
    {
        DB::transaction(function () use ($plan) {
            CreditAllocation::whereNull('payment_id')
                ->whereIn('credit_note_id', Document::whereNotNull('legacy_uid')->select('id'))
                ->forceDelete();

            foreach (array_chunk($plan['allocation_rows'], 1000) as $chunk) {
                CreditAllocation::insert($chunk);
            }

            foreach ($plan['credited_invoice_updates'] as $update) {
                Document::whereKey($update['document_id'])->update(['credited_invoice_id' => $update['invoice_id']]);
            }
        });
    }
}
