<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SupplierInvoiceItem;
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
        $discount = round($base * $discountPercent / 100, 2);
        $payable = max(0, round($gross - $discount, 2));

        return [
            'net' => $net,
            'vat' => $vat,
            'gross' => $gross,
            'discount' => $discount,
            'payable' => $payable,
        ];
    }
}
