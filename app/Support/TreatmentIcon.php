<?php

namespace App\Support;

use App\Models\Treatment;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Ícono de cada tratamiento en la web, elegido solo por su nombre (no hay que configurarlo).
 * Trazos simples de 24 × 24 que toman el color del texto.
 */
final class TreatmentIcon
{
    /** @var array<string, list<string>> Ícono → comienzos de palabra (sin tildes) que lo eligen, en orden de prioridad. */
    private const KEYWORDS = [
        'child' => ['nino', 'odontopediatr', 'pediatr', 'infantil'],
        'braces' => ['ortodon', 'bracket', 'alineador', 'retenedor'],
        'implant' => ['implant'],
        'crown' => ['corona', 'carilla', 'protesis', 'puente', 'incrustacion', 'diseno de sonrisa'],
        'root' => ['endodon', 'conducto', 'nervio', 'pulp'],
        'extraction' => ['extracc', 'exodon', 'cirugia', 'muela del juicio', 'tercera molar', 'terceras molares'],
        'gum' => ['periodon', 'encia', 'gingiv', 'raspaje', 'curetaje'],
        'sparkle' => ['limpieza', 'profilaxis', 'blanquea', 'fluor', 'destartraje'],
        'filling' => ['resina', 'curacion', 'caries', 'obturac', 'sellante'],
        'clipboard' => ['evaluac', 'consulta', 'diagnost', 'control', 'radiograf'],
    ];

    /** @var array<string, string> Trazos SVG de cada ícono. */
    private const PATHS = [
        'tooth' => '<path d="M7.5 3C5 3 3.5 5 3.5 7.4c0 1.9.6 3.4 1.2 5 .7 1.8 1 3.6 1.3 5.6.2 1.7.8 3 1.8 3 1.3 0 1.6-1.6 2-3.5.3-1.4.8-2.5 2.2-2.5s1.9 1.1 2.2 2.5c.4 1.9.7 3.5 2 3.5 1 0 1.6-1.3 1.8-3 .3-2 .6-3.8 1.3-5.6.6-1.6 1.2-3.1 1.2-5C20.5 5 19 3 16.5 3c-1.8 0-2.7 1-4.5 1S9.3 3 7.5 3Z"/>',
        'sparkle' => '<path d="M12 3c.6 3.9 2.1 5.4 6 6-3.9.6-5.4 2.1-6 6-.6-3.9-2.1-5.4-6-6 3.9-.6 5.4-2.1 6-6Z"/><path d="M18.5 14.5c.3 1.8 1 2.5 2.5 2.8-1.5.3-2.2 1-2.5 2.7-.3-1.7-1-2.4-2.5-2.7 1.5-.3 2.2-1 2.5-2.8ZM5.5 15c.2 1.2.7 1.7 1.8 1.9-1.1.2-1.6.7-1.8 1.9-.2-1.2-.7-1.7-1.8-1.9 1.1-.2 1.6-.7 1.8-1.9Z"/>',
        'braces' => '<rect x="3" y="7" width="18" height="10" rx="4"/><path d="M3 12h18M8 9.5v5M12 9.5v5M16 9.5v5"/>',
        'implant' => '<path d="M7 3h10l-1.2 4.5H8.2L7 3Z"/><path d="M9 10h6M9.4 13h5.2M9.8 16h4.4M10.6 19h2.8M12 7.5V21"/>',
        'crown' => '<path d="M4 8.5 8 12l4-7 4 7 4-3.5-1.8 10.5H5.8L4 8.5Z"/><path d="M6 21h12"/>',
        'root' => '<path d="M7.5 3C5 3 3.5 5 3.5 7.4c0 1.9.6 3.4 1.2 5 .7 1.8 1 3.6 1.3 5.6.2 1.7.8 3 1.8 3 1.3 0 1.6-1.6 2-3.5.3-1.4.8-2.5 2.2-2.5s1.9 1.1 2.2 2.5c.4 1.9.7 3.5 2 3.5 1 0 1.6-1.3 1.8-3 .3-2 .6-3.8 1.3-5.6.6-1.6 1.2-3.1 1.2-5C20.5 5 19 3 16.5 3c-1.8 0-2.7 1-4.5 1S9.3 3 7.5 3Z"/><path d="M12 7v7"/>',
        'extraction' => '<path d="M8.5 9C6.8 9 6 10.3 6 11.8c0 1.3.4 2.3.8 3.4.5 1.2.7 2.4.9 3.8.1 1.1.5 2 1.2 2 .9 0 1.1-1 1.4-2.3.2-1 .6-1.7 1.7-1.7s1.5.7 1.7 1.7c.3 1.3.5 2.3 1.4 2.3.7 0 1.1-.9 1.2-2 .2-1.4.4-2.6.9-3.8.4-1.1.8-2.1.8-3.4C18 10.3 17.2 9 15.5 9c-1.2 0-1.8.7-3.5.7S9.7 9 8.5 9Z"/><path d="M12 6V2M9.5 4 12 2l2.5 2"/>',
        'child' => '<circle cx="12" cy="12" r="9"/><path d="M8.5 14.5c1.9 2 5.1 2 7 0M9 10h.01M15 10h.01"/>',
        'filling' => '<path d="M7.5 3C5 3 3.5 5 3.5 7.4c0 1.9.6 3.4 1.2 5 .7 1.8 1 3.6 1.3 5.6.2 1.7.8 3 1.8 3 1.3 0 1.6-1.6 2-3.5.3-1.4.8-2.5 2.2-2.5s1.9 1.1 2.2 2.5c.4 1.9.7 3.5 2 3.5 1 0 1.6-1.3 1.8-3 .3-2 .6-3.8 1.3-5.6.6-1.6 1.2-3.1 1.2-5C20.5 5 19 3 16.5 3c-1.8 0-2.7 1-4.5 1S9.3 3 7.5 3Z"/><circle cx="12" cy="9" r="2"/>',
        'gum' => '<path d="M3 9c3 3.5 15 3.5 18 0"/><path d="M6.5 11.5v4.5c0 1.5.7 3 1.6 3s1.4-1.5 1.4-3v-3.4M14.5 12.6V16c0 1.5.5 3 1.4 3s1.6-1.5 1.6-3v-4.5"/>',
        'clipboard' => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 3h6v3H9zM8.5 11h7M8.5 15h4.5"/>',
    ];

    public static function for(Treatment $treatment): HtmlString
    {
        return self::svg(self::key($treatment->name.' '.$treatment->slug));
    }

    public static function key(string $text): string
    {
        // Por el comienzo de cada palabra: "encía" no debe encontrarse dentro de "urgencia".
        $text = ' '.Str::of($text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        foreach (self::KEYWORDS as $icon => $words) {
            foreach ($words as $word) {
                if (str_contains($text, ' '.$word)) {
                    return $icon;
                }
            }
        }

        return 'tooth';
    }

    public static function svg(string $key): HtmlString
    {
        $paths = self::PATHS[$key] ?? self::PATHS['tooth'];

        return new HtmlString('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>');
    }
}
