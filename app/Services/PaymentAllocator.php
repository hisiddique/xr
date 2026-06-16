<?php

namespace App\Services;

use App\DocumentType;
use App\Models\CreditAllocation;
use App\Models\Document;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use Illuminate\Support\Facades\DB;

class PaymentAllocator
{
    /**
     * Auto-allocate payment across unpaid/partially-paid invoices oldest-first (FIFO).
     *
     * @return array<int, float>
     */
    public function autoAllocate(Payment $payment): array
    {
        $invoices = Document::where('customer_id', $payment->customer_id)
            ->where('type', DocumentType::Invoice)
            ->orderBy('doc_date', 'asc')
            ->withSum('paymentAllocations', 'allocated_amount')
            ->get();

        $remaining = (float) $payment->amount;
        $allocations = [];

        foreach ($invoices as $invoice) {
            if ($remaining <= 0) {
                break;
            }

            $outstanding = (float) $invoice->total_value - (float) ($invoice->payment_allocations_sum_allocated_amount ?? 0);

            if ($outstanding <= 0) {
                continue;
            }

            $allocated = min($outstanding, $remaining);
            $allocations[$invoice->id] = $allocated;
            $remaining -= $allocated;
        }

        return $allocations;
    }

    /**
     * Persist allocations for a payment.
     *
     * @param  array<int, float>  $allocations  [document_id => allocated_amount]
     */
    /**
     * @param  array<int, float>  $allocations  [document_id => allocated_amount]
     * @param  int[]  $scope  All document IDs presented in the form (used to scope deletes). Defaults to keys of $allocations.
     */
    public function saveAllocations(Payment $payment, array $allocations, array $scope = []): void
    {
        if (array_sum($allocations) > (float) $payment->amount + 0.001) {
            throw new \InvalidArgumentException('Allocated amount exceeds payment amount.');
        }

        $scope = $scope ?: array_keys($allocations);

        DB::transaction(function () use ($payment, $allocations, $scope) {
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
     * @param  array<int, float>  $paymentAllocations  [document_id => payment_amount]
     * @param  array<int, array<int, float>>  $creditAllocations  [credit_note_id => [invoice_id => amount]]
     * @param  int[]  $scope  All invoice IDs presented in the form (drives stale-allocation cleanup)
     */
    public function saveWithCredits(
        Payment $payment,
        array $paymentAllocations,
        array $creditAllocations,
        array $scope = []
    ): void {
        DB::transaction(function () use ($payment, $paymentAllocations, $creditAllocations, $scope) {
            $this->saveAllocations($payment, $paymentAllocations, $scope);

            $cleanupScope = ! empty($scope) ? $scope : collect($creditAllocations)
                ->flatMap(fn ($rows) => array_keys($rows))
                ->unique()
                ->values()
                ->toArray();

            if (! empty($cleanupScope)) {
                CreditAllocation::where('payment_id', $payment->id)
                    ->whereIn('invoice_id', $cleanupScope)
                    ->delete();
            }

            foreach ($creditAllocations as $creditNoteId => $invoiceAllocations) {
                foreach ($invoiceAllocations as $invoiceId => $amount) {
                    if ($amount <= 0) {
                        continue;
                    }
                    CreditAllocation::create([
                        'payment_id' => $payment->id,
                        'credit_note_id' => $creditNoteId,
                        'invoice_id' => $invoiceId,
                        'amount' => $amount,
                    ]);
                }
            }
        });
    }
}
