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

    /** Saldo del paciente en palabras: "Debe S/ 80.00", "A favor S/ 20.00" o "Al día". */
    public static function balance(float|int|string|null $balance): string
    {
        $balance = round((float) $balance, 2);

        return match (true) {
            $balance > 0 => 'Debe '.self::format($balance),
            $balance < 0 => 'A favor '.self::format(-$balance),
            default => 'Al día',
        };
    }
}
