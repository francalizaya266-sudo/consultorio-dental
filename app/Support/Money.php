<?php

namespace App\Support;

/** Montos en soles, como se escriben en el Perú: "S/ 1,250.00". */
final class Money
{
    public static function format(float|int|string|null $amount): string
    {
        return 'S/ '.number_format((float) $amount, 2);
    }
}
