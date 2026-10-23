<?php

namespace App\Support;

/** Montos en soles, como se escriben en el Perú: "S/ 1,250.00". */
final class Money
{
    public static function format(float|int|string|null $amount): string
    {
        return 'S/ '.number_format((float) $amount, 2);
    }

    /** Para la web: sin decimales cuando el monto es exacto ("S/ 150"). */
    public static function short(float|int|string|null $amount): string
    {
        $amount = (float) $amount;

        return 'S/ '.number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
    }
}
