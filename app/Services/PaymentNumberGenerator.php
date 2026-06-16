<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;

class PaymentNumberGenerator
{
    public function next(): string
    {
        return DB::transaction(function () {
            $prefix = (string) Setting::get('pay_prefix', 'PAY');
            $padding = (int) Setting::get('number_padding', 4);

            /** @var Payment|null $last */
            $last = Payment::lockForUpdate()
                ->orderByDesc('id')
                ->first();

            $nextSequence = $last ? $this->extractSequence($last->reference) + 1 : 1;

            return sprintf('%s-%s', $prefix, str_pad((string) $nextSequence, $padding, '0', STR_PAD_LEFT));
        });
    }

    public function extractSequence(string $reference): int
    {
        $parts = explode('-', $reference);

        return (int) end($parts);
    }
}
