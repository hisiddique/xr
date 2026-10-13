<?php

namespace App\Support;

class Money
{
    public static function roundUp(float $amount): float
    {
        return ceil(round($amount * 100, 6)) / 100;
    }
}
