<?php

namespace App\Services;

use App\Models\SupplierInvoice;
use App\Models\SupplierPayout;
use App\Models\SupplierPayoutAllocation;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SupplierPayoutAllocator
{
    public function autoAllocate(SupplierPayout $payout): array
    {
        $invoices = SupplierInvoice::where('supplier_id', $payout->supplier_id)
            ->orderBy('invoice_date')
            ->with([
                'items',
                'payoutAllocations' => fn ($q) => $q->where('supplier_payout_id', '!=', $payout->id ?? 0),
                'debitNotes',
            ])
            ->get();

        $remaining = (float) $payout->amount;
        $rows = [];

        foreach ($invoices as $invoice) {
            $grossTotal = $invoice->grossTotal;
            $paid = (float) $invoice->payoutAllocations->sum('allocated_amount');
            $deducted = (float) $invoice->debitNotes->sum(fn ($dn) => (float) $dn->pivot->applied_amount);
            $effective = max(0, $grossTotal - $paid - $deducted);

            if ($effective <= 0 || $remaining <= 0) {
                continue;
            }

            $alloc = min($effective, $remaining);
            $remaining = round($remaining - $alloc, 2);

            $debitNote = $invoice->debitNotes->first();

            $rows[] = [
                'invoice_id' => $invoice->id,
                'debit_note_id' => $debitNote?->id,
                'deduction_amount' => $deducted,
                'allocated_amount' => round($alloc, 2),
            ];
        }

        return $rows;
    }

    public function save(SupplierPayout $payout, array $rows): void
    {
        $total = array_sum(array_column($rows, 'allocated_amount'));

        if ($total > (float) $payout->amount + 0.001) {
            throw new InvalidArgumentException(
                "Total allocated ({$total}) exceeds payout amount ({$payout->amount})."
            );
        }

        DB::transaction(function () use ($payout, $rows): void {
            $payout->allocations()->delete();

            foreach ($rows as $row) {
                if ((float) ($row['allocated_amount'] ?? 0) <= 0) {
                    continue;
                }

                SupplierPayoutAllocation::create([
                    'supplier_payout_id' => $payout->id,
                    'supplier_invoice_id' => $row['invoice_id'] ?? null,
                    'supplier_debit_note_id' => $row['debit_note_id'] ?? null,
                    'deduction_amount' => (float) ($row['deduction_amount'] ?? 0),
                    'allocated_amount' => (float) $row['allocated_amount'],
                ]);
            }
        });
    }
}
