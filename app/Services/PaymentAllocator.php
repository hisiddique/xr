<?php

namespace App\Services;

use App\DocumentType;
use App\Models\CreditAllocation;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentDraw;
use App\PaymentSourceType;
use Illuminate\Support\Facades\DB;

class PaymentAllocator
{
    /**
     * Auto-allocate payment across unpaid/partially-paid invoices oldest-first (FIFO).
     *
     * When re-running for an already-persisted payment, this payment's own
     * prior cash/credit contributions are excluded from "already allocated"
     * so its own slot on each invoice is treated as free to redistribute,
     * not double-counted as already consumed.
     *
     * @return array<int, float>
     */
    public function autoAllocate(Payment $payment): array
    {
        $invoices = Document::where('customer_id', $payment->customer_id)
            ->where('type', DocumentType::Invoice)
            ->orderBy('doc_date', 'asc')
            ->withSum(['paymentAllocations' => function ($query) use ($payment) {
                if ($payment->exists) {
                    $query->where('payment_id', '!=', $payment->id);
                }
            }], 'allocated_amount')
            ->withSum(['creditAllocationsReceived' => function ($query) use ($payment) {
                if ($payment->exists) {
                    $query->where(fn ($q) => $q->whereNull('payment_id')->orWhere('payment_id', '!=', $payment->id));
                }
            }], 'amount')
            ->get();

        $remaining = (float) $payment->amount;
        $allocations = [];

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            $outstanding = (float) $invoice->total_value
                - (float) ($invoice->payment_allocations_sum_allocated_amount ?? 0)
                - (float) ($invoice->credit_allocations_received_sum_amount ?? 0);

            if ($outstanding <= 0.001) {
                continue;
            }

            $allocated = round(min($outstanding, $remaining), 2);
            $allocations[$invoice->id] = $allocated;
            $remaining -= $allocated;
        }

        return $allocations;
    }

    /**
     * Persist allocations for a payment.
     *
     * @param  array<int, float>  $allocations  [document_id => allocated_amount]
     * @param  int[]  $scope  All document IDs presented in the form (used to scope deletes). Defaults to keys of $allocations.
     */
    public function saveAllocations(Payment $payment, array $allocations, array $scope = []): void
    {
        $scope = $scope ?: array_keys($allocations);

        DB::transaction(function () use ($payment, $allocations, $scope) {
            $locked = Payment::lockForUpdate()->find($payment->id);
            $drawnAway = (float) $locked->drawsMade()->sum('amount');
            $budget = (float) $locked->amount - $drawnAway;

            if (array_sum($allocations) > $budget + 0.001) {
                throw new \InvalidArgumentException('Allocated amount exceeds payment amount.');
            }

            foreach ($allocations as $documentId => $amount) {
                if ($amount > 0) {
                    PaymentAllocation::updateOrCreate(
                        ['payment_id' => $payment->id, 'document_id' => $documentId],
                        ['allocated_amount' => $amount]
                    );
                }
            }

            if (! empty($scope)) {
                PaymentAllocation::where('payment_id', $payment->id)
                    ->whereIn('document_id', $scope)
                    ->whereNotIn('document_id', array_keys($allocations))
                    ->delete();
            }
        });
    }

    /**
     * Fund a payment from explicitly selected credit notes with remaining balance.
     *
     * Each selected note is consumed in full — its entire *remaining* value
     * (total_value minus whatever's already been drawn against it, including
     * legacy pre-selection draws) is consumed only up to `$amountNeeded` —
     * drawn oldest-first across the selected notes. Whatever a note doesn't
     * need to give up stays on the note, untouched and available for other
     * payments; it never becomes this payment's own spendable over-payment
     * balance. `$amountNeeded` must already include anything already drawn
     * away from this payment by a later over-payment (see
     * Payment::drawsMade()) so a re-save never shrinks the payment below
     * history that already depends on it.
     *
     * @param  int[]  $creditNoteIds
     */
    public function fundFromCreditNotes(Payment $payment, array $creditNoteIds, float $amountNeeded): void
    {
        DB::transaction(function () use ($payment, $creditNoteIds, $amountNeeded) {
            $creditNoteIds = array_values(array_unique($creditNoteIds));
            $remainingNeeded = round(max(0, $amountNeeded), 2);

            $creditNotes = Document::whereIn('id', $creditNoteIds)
                ->where('type', DocumentType::CreditNote)
                ->where('customer_id', $payment->customer_id)
                ->withSum(['creditAllocations' => fn ($query) => $query
                    ->where(fn ($q) => $q->whereNull('payment_id')->orWhere('payment_id', '!=', $payment->id))], 'amount')
                ->orderBy('doc_date', 'asc')
                ->lockForUpdate()
                ->get();

            if ($creditNotes->count() !== count($creditNoteIds)) {
                throw new \InvalidArgumentException('One or more selected credit notes could not be found.');
            }

            CreditAllocation::where('payment_id', $payment->id)->forceDelete();

            $total = 0.0;
            foreach ($creditNotes as $creditNote) {
                if ($remainingNeeded <= 0.001) {
                    break;
                }

                $available = round((float) $creditNote->total_value - (float) ($creditNote->credit_allocations_sum_amount ?? 0), 2);

                if ($available <= 0.001) {
                    continue;
                }

                $consume = round(min($available, $remainingNeeded), 2);

                CreditAllocation::create([
                    'payment_id' => $payment->id,
                    'credit_note_id' => $creditNote->id,
                    'invoice_id' => null,
                    'amount' => $consume,
                ]);
                $total += $consume;
                $remainingNeeded -= $consume;
            }

            if ($remainingNeeded > 0.001) {
                throw new \InvalidArgumentException('Selected credit notes do not cover the amount needed.');
            }

            $payment->amount = round($total, 2);
            $payment->save();
        });
    }

    /**
     * Fund a payment by drawing the full remaining balance of explicitly
     * selected prior payments (excluding other over-payment-funded payments,
     * which cannot be chained). Also applies the reversible "exhausted" flag
     * across the picker's candidate scope.
     *
     * @param  int[]  $sourcePaymentIds
     * @param  int[]  $exhaustIds  Source payments to flag as exhausted
     * @param  int[]  $exhaustScope  Candidate source payment IDs shown in the picker (drives un-flagging of unchecked ones); narrowed internally to sources this payment previously or currently draws from
     */
    public function fundFromOverPayments(Payment $payment, array $sourcePaymentIds, array $exhaustIds = [], array $exhaustScope = []): void
    {
        DB::transaction(function () use ($payment, $sourcePaymentIds, $exhaustIds, $exhaustScope) {
            $sourcePaymentIds = array_values(array_unique($sourcePaymentIds));

            $sources = Payment::whereIn('id', $sourcePaymentIds)
                ->where('customer_id', $payment->customer_id)
                ->where('id', '!=', $payment->id)
                ->where('source_type', '!=', PaymentSourceType::OverPayment)
                ->lockForUpdate()
                ->get();

            if ($sources->count() !== count($sourcePaymentIds)) {
                throw new \InvalidArgumentException('One or more selected payments could not be found.');
            }

            $previouslyDrawnIds = $payment->drawsReceived()->pluck('source_payment_id')->toArray();
            $exhaustScope = array_intersect($exhaustScope, array_unique(array_merge($previouslyDrawnIds, $sourcePaymentIds)));

            PaymentDraw::where('target_payment_id', $payment->id)->forceDelete();

            $total = 0.0;
            foreach ($sources as $source) {
                $remaining = round($source->remainingBalance(), 2);
                if ($remaining <= 0.001) {
                    throw new \InvalidArgumentException("Selected payment {$source->reference} has no remaining balance.");
                }
                PaymentDraw::create([
                    'source_payment_id' => $source->id,
                    'target_payment_id' => $payment->id,
                    'amount' => $remaining,
                ]);
                $total += $remaining;
            }

            $payment->amount = $total;
            $payment->save();

            if (! empty($exhaustScope)) {
                Payment::whereIn('id', $exhaustScope)->update(['is_exhausted' => false]);
            }
            if (! empty($exhaustIds)) {
                Payment::whereIn('id', $exhaustIds)->update(['is_exhausted' => true]);
            }
        });
    }
}
