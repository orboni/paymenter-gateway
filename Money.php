<?php

namespace Paymenter\Extensions\Gateways\Orboni;

/** Amounts between Paymenter's decimals and Orboni's smallest units. */
class Money
{
    /** 12.5 with 2 decimals is 1250; 1500 yen with none is 1500. */
    public static function minor(float|int|string $amount, int $decimals): int
    {
        return (int) round((float) $amount * (10 ** $decimals));
    }

    /** 1250 with 2 decimals is "12.50". */
    public static function major(int $minor, int $decimals): string
    {
        return number_format($minor / (10 ** $decimals), $decimals, '.', '');
    }
}
