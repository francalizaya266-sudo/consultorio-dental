<?php

namespace App\Support;

/** Cantidades de material como se dicen: "1 carpule", "12 carpules", "2.5 ml", "3 unidades". */
final class Quantity
{
    public static function format(float|int|string|null $quantity, string $unit): string
    {
        $quantity = round((float) $quantity, 2);

        return self::number($quantity).' '.self::unit($unit, $quantity);
    }

    /** "12", "2.5", "−3": sin ceros de más. */
    public static function number(float|int|string|null $quantity): string
    {
        $quantity = round((float) $quantity, 2);
        $text = rtrim(rtrim(number_format(abs($quantity), 2, '.', ','), '0'), '.');

        return ($quantity < 0 ? '−' : '').$text;
    }

    /** Las abreviaturas (ml, g, cm) no cambian; el resto va en plural si no es exactamente uno. */
    public static function unit(string $unit, float $quantity): string
    {
        $unit = trim($unit);

        if (abs($quantity) === 1.0 || mb_strlen($unit) <= 2 || str_contains($unit, ' ') || str_ends_with($unit, 's')) {
            return $unit;
        }

        // "pares", "unidades"; pero "kits", como se dice.
        return preg_match('/[lrndj]$/iu', $unit) === 1 ? $unit.'es' : $unit.'s';
    }
}
