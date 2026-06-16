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
     * @param  Collection<int, array{quantity?: float|string, price?: float|string, per?: string|null, is_note?: bool}>  $items
     * @return array{subtotal: float, discount: float, discount_amount: float, vat: float, total: float}
     */
    public function calculate(Collection $items, Customer $customer): array
    {
        $subtotal = $items->sum(function (array $item) {
            if (! empty($item['is_note'])) {
                return 0;
            }

            return self::lineValue($item);
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

    /**
     * @param  Collection<int, array{refund_amount?: float|string, is_note?: bool}>  $items
     */
    public static function creditNoteTotal(Collection $items, float $globalAmount = 0.00): float
    {
        $itemsRefund = $items->sum(function (array $item) {
            if (! empty($item['is_note'])) {
                return 0;
            }

            return (float) ($item['refund_amount'] ?? 0);
        });

        return round($itemsRefund + $globalAmount, 2);
    }

    /**
     * Compute a single line value. When `per` is a positive numeric pack size,
     * the formula is (qty / per) * price; otherwise qty * price.
     *
     * @param  array{quantity?: float|string, price?: float|string, per?: string|null}  $item
     */
    public static function lineValue(array $item): float
    {
        $qty = (float) ($item['quantity'] ?? 0);
        $price = (float) ($item['price'] ?? 0);
        $per = $item['per'] ?? null;

        if (is_numeric($per) && (float) $per > 0) {
            return ($qty / (float) $per) * $price;
        }

        return $qty * $price;
    }
}
