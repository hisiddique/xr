<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Setting;
use Illuminate\Support\Collection;

class DocumentTotalsCalculator
{
    /**
     * Calculate document totals from line items and customer discount.
     *
     * @param  Collection<int, array{quantity: float|string, price: float|string}>  $items
     * @return array{subtotal: float, discount: float, discount_amount: float, vat: float, total: float}
     */
    public function calculate(Collection $items, Customer $customer): array
    {
        $subtotal = $items->sum(function (array $item) {
            if (! empty($item['is_note'])) {
                return 0;
            }

            return (float) $item['quantity'] * (float) $item['price'];
        });

        $discountPercent = (float) $customer->trade_discount;
        $discountAmount = round($subtotal * ($discountPercent / 100), 2);
        $afterDiscount = $subtotal - $discountAmount;

        $vatRate = (float) Setting::get('vat_rate', 20);
        $vatAmount = round($afterDiscount * ($vatRate / 100), 2);

        $total = $afterDiscount + $vatAmount;

        return [
            'subtotal' => round($subtotal, 2),
            'discount' => $discountPercent,
            'discount_amount' => $discountAmount,
            'vat' => round($vatAmount, 2),
            'total' => round($total, 2),
        ];
    }
}
