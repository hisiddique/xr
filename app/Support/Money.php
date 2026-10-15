<?php

namespace App\Support;

class Money
{
    public static function round(float $amount): float
    {
        return round($amount, 2);
    }

    public static function roundDown(float $amount): float
    {
        return floor(round($amount * 100, 6)) / 100;
    }

    public static function roundUp(float $amount): float
    {
        return ceil(round($amount * 100, 6)) / 100;
    }
}
