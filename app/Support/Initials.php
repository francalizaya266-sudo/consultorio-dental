<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Iniciales para avatares y retratos sin foto, sin el "Dr." delante y con el primer apellido:
 * "Dr. Jassmanny Neil Vega Jaen" da "JV" y "Dra. Ana Torres Ruiz", "AT".
 */
final class Initials
{
    public static function of(string $name): string
    {
        $words = Str::of($name)
            ->replaceMatches('/^(Dra?\.|Dr\(a\)\.)\s*/iu', '')
            ->squish()
            ->explode(' ')
            ->filter()
            ->values();

        // Con cuatro palabras o más, las dos primeras son nombres y la tercera es el primer apellido.
        $surname = $words->get($words->count() >= 4 ? 2 : 1);

        return collect([$words->first(), $surname])
            ->filter()
            ->map(fn (string $word) => Str::upper(Str::substr($word, 0, 1)))
            ->implode('');
    }
}
