<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SupplierDebitNote;
use App\Models\SupplierInvoiceItem;
use App\Support\Money;
use Illuminate\Support\Collection;

class SupplierInvoiceTotalsCalculator
{
    public static function lineVat(float $net, bool $vatApplicable, float $rate, mixed $grossOverride = null): float
    {
        if (! $vatApplicable) {
            return 0.0;
        }

        if (is_numeric($grossOverride)) {
            return round((float) $grossOverride - $net, 2);
        }

        return Money::roundDown($net * $rate / 100);
    }

    /**
     * Splits a VAT-inclusive amount into net and VAT; VAT is rounded to the penny and net is the remainder.
     *
     * @return array{net: float, vat: float}
     */
    public static function splitInclusive(float $gross, bool $vatApplicable, float $rate): array
    {
        $gross = round($gross, 2);

        if (! $vatApplicable) {
            return ['net' => $gross, 'vat' => 0.0];
        }

        $vat = Money::round($gross * $rate / (100 + $rate));

        return ['net' => round($gross - $vat, 2), 'vat' => $vat];
    }

    /**
     * Net and VAT of one debit-note line. A VAT-inclusive note treats the line value as gross, extracts the VAT,
     * then takes the discount off the net only (VAT is unchanged). A legacy note treats the line value as net,
     * the discount % as a percentage credit of it, and adds VAT on top. A manual net or VAT
     * override replaces the calculated figure on its own (VAT is ignored when the line has no VAT).
     *
     * @return array{net: float, vat: float}
     */
    public static function debitNoteLine(float $lineValue, float $discountPercent, bool $vatApplicable, bool $inclusive, float $rate, ?float $netOverride = null, ?float $vatOverride = null): array
    {
        if ($inclusive) {
            $line = self::splitInclusive($lineValue, $vatApplicable, $rate);
            $line['net'] = round($line['net'] - Money::round($line['net'] * $discountPercent / 100), 2);
        } else {
            $net = $discountPercent > 0 ? Money::roundDown($lineValue * $discountPercent / 100) : $lineValue;
            $line = ['net' => $net, 'vat' => $vatApplicable ? Money::roundDown($net * $rate / 100) : 0.0];
        }

        if ($netOverride !== null) {
            $line['net'] = round($netOverride, 2);
        }

        if ($vatOverride !== null && $vatApplicable) {
            $line['vat'] = round($vatOverride, 2);
        }

        return $line;
    }

    /**
     * Calculate supplier invoice totals from line items and a trade-discount percentage.
     *
     * @param  Collection<int, SupplierInvoiceItem>|iterable  $items
     * @return array{net: float, vat: float, gross: float, discount: float, payable: float}
     */
    public function calculate(iterable $items, float $discountPercent, bool $discountOnGross, ?float $vatRate = null): array
    {
        $rate = $vatRate ?? (float) Setting::get('vat_rate', 20);

        $net = 0.0;
        $vat = 0.0;

        foreach ($items as $item) {
            $lineTotal = (float) data_get($item, 'line_total');
            $net += $lineTotal;

            if (data_get($item, 'vat_applicable')) {
                $snapshot = data_get($item, 'vat_amount');
                $vat += $snapshot !== null && $snapshot !== ''
                    ? (float) $snapshot
                    : self::lineVat($lineTotal, true, $rate, data_get($item, 'gross_override'));
            }
        }

        $net = round($net, 2);
        $vat = round($vat, 2);
        $gross = round($net + $vat, 2);

        $base = $discountOnGross ? $gross : $net;
        $discount = Money::roundDown($base * $discountPercent / 100);
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
     * trade discount on net, the invoice is re-priced before and after each note with the same formula
     * (discount on net, VAT on the undiscounted net) so the returned goods give back their share of
     * the discount and VAT without a pricing-method shift landing on the first note.
     *
     * @param  iterable<int, array{line_total: float|int|string, vat_applicable: bool}>  $lineItems
     * @param  iterable<int, SupplierDebitNote>  $notes
     * @return array<int, float>
     */
    public function debitNoteDeductions(iterable $lineItems, float $percent, bool $discountOnGross, iterable $notes, ?float $vatRate = null): array
    {
        $deductions = [];

        if ($percent <= 0 || $discountOnGross) {
            foreach ($notes as $note) {
                $deductions[$note->id] = (float) $note->total;
            }

            return $deductions;
        }

        $rate = $vatRate ?? (float) Setting::get('vat_rate', 20);
        $lines = collect($lineItems);
        $net = (float) $lines->sum(fn ($line) => (float) data_get($line, 'line_total'));
        $vat = (float) $lines->filter(fn ($line) => (bool) data_get($line, 'vat_applicable'))
            ->sum(function ($line) use ($rate) {
                $snapshot = data_get($line, 'vat_amount');

                return $snapshot !== null && $snapshot !== ''
                    ? (float) $snapshot
                    : self::lineVat((float) data_get($line, 'line_total'), true, $rate, data_get($line, 'gross_override'));
            });
        $previous = $this->payableFor($net, $vat, $percent);

        foreach ($notes as $note) {
            $net -= (float) $note->items->sum('total');
            $vat -= (float) $note->items->where('vat_applicable', true)->sum(
                fn ($item) => $item->vat_amount !== null
                    ? (float) $item->vat_amount
                    : Money::roundDown((float) $item->total * $rate / 100)
            );

            $current = $this->payableFor(max(0.0, $net), max(0.0, $vat), $percent);
            $deductions[$note->id] = max(0.0, round($previous - $current, 2));
            $previous = $current;
        }

        return $deductions;
    }

    private function payableFor(float $net, float $vat, float $percent): float
    {
        if ($net <= 0) {
            return 0.0;
        }

        return max(0.0, round($net - Money::roundDown($net * $percent / 100) + $vat, 2));
    }
}
