<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoiceItem;
use App\Support\Money;
use Illuminate\Support\Collection;

class SupplierInvoiceTotalsCalculator
{
    /**
     * Calculate supplier invoice totals from line items and a trade-discount percentage.
     *
     * @param  Collection<int, SupplierInvoiceItem>|iterable  $items
     * @return array{net: float, vat: float, gross: float, discount: float, payable: float}
     */
    public function calculate(iterable $items, float $discountPercent, bool $discountOnGross): array
    {
        $rate = (float) Setting::get('vat_rate', 20);

        $net = 0.0;
        $vat = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) data_get($item, 'line_total');
            $net += $lineTotal;

            if (data_get($item, 'vat_applicable')) {
                $vat += round($lineTotal * $rate / 100, 2);
            }
        }

        $net = round($net, 2);
        $vat = round($vat, 2);
        $gross = round($net + $vat, 2);

        $base = $discountOnGross ? $gross : $net;
        $discount = Money::roundUp($base * $discountPercent / 100);
        $payable = max(0, round($gross - $discount, 2));

        return [
            'net' => $net,
            'vat' => $vat,
            'gross' => $gross,
            'discount' => $discount,
            'payable' => $payable,
        ];
    }

    /**
     * Amount each debit note takes off the invoice, in the order given. When the invoice carries a
     * trade discount on net, the invoice is re-priced on the reduced net (discount first, then VAT)
     * so the returned goods give back their share of the discount and VAT.
     *
     * @param  iterable<int, array{line_total: float|int|string, vat_applicable: bool}>  $lineItems
     * @param  iterable<int, SupplierDebitNote>  $notes
     * @return array<int, float>
     */
    public function debitNoteDeductions(iterable $lineItems, float $percent, bool $discountOnGross, iterable $notes): array
    {
        $deductions = [];

        if ($percent <= 0 || $discountOnGross) {
            foreach ($notes as $note) {
                $deductions[$note->id] = (float) $note->total;
            }

            return $deductions;
        }

        $rate = (float) Setting::get('vat_rate', 20);
        $lines = collect($lineItems);
        $net = (float) $lines->sum(fn ($line) => (float) data_get($line, 'line_total'));
        $vatNet = (float) $lines->filter(fn ($line) => (bool) data_get($line, 'vat_applicable'))
            ->sum(fn ($line) => (float) data_get($line, 'line_total'));
        $previous = $this->calculate($lines, $percent, false)['payable'];

        foreach ($notes as $note) {
            $net -= (float) $note->items->sum('total');
            $vatNet -= (float) $note->items->where('vat_applicable', true)->sum('total');

            $current = $this->payableOnReducedNet(max(0.0, $net), max(0.0, $vatNet), $percent, $rate);
            $deductions[$note->id] = max(0.0, round($previous - $current, 2));
            $previous = $current;
        }

        return $deductions;
    }

    private function payableOnReducedNet(float $net, float $vatNet, float $percent, float $rate): float
    {
        if ($net <= 0) {
            return 0.0;
        }

        $discount = Money::roundUp($net * $percent / 100);
        $vatBase = max(0.0, $vatNet - $discount * $vatNet / $net);
        $vat = Money::roundUp($vatBase * $rate / 100);

        return max(0.0, round($net - $discount + $vat, 2));
    }
}
