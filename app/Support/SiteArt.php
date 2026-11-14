<?php

namespace App\Support;

use App\Models\Treatment;
use Illuminate\Support\HtmlString;

/**
 * Imágenes 3D de la web (public/img/3d): la portada, los tres pasos y una por tipo de tratamiento.
 * La de cada tratamiento se elige sola por su nombre, igual que su ícono: no hay nada que configurar.
 */
final class SiteArt
{
    /** Sube cuando se reemplaza alguna imagen: el celular las guarda un mes. */
    private const VERSION = 1;

    /** @var array<string, string> Ícono del tratamiento (TreatmentIcon) → su imagen. */
    private const BY_ICON = [
        'tooth' => 'diente',
        'clipboard' => 'evaluacion',
        'sparkle' => 'limpieza',
        'filling' => 'resina',
        'extraction' => 'extraccion',
        'braces' => 'brackets',
        'implant' => 'implante',
        'root' => 'endodoncia',
        'crown' => 'corona',
        'gum' => 'encia',
        'child' => 'ninos',
    ];

    /** @var list<string> Imágenes que dicen una especialidad (no lo de todos los días: evaluar, limpiar, curar). */
    private const SPECIALTIES = ['implante', 'brackets', 'endodoncia', 'corona', 'encia', 'ninos'];

    /** Dirección de una imagen: "portada", "sonrisa", "implante"… */
    public static function url(string $name): string
    {
        return asset('img/3d/'.$name.'.webp').'?v='.self::VERSION;
    }

    /**
     * Qué imagen le toca a un tratamiento, por su nombre. Donde dos compartían ícono, cada uno tiene la suya:
     * el blanqueamiento no es una limpieza, y el control de ortodoncia no es la colocación de brackets.
     */
    public static function key(string $text): string
    {
        $image = self::BY_ICON[TreatmentIcon::key($text)];
        $words = TreatmentIcon::words($text);

        return match (true) {
            $image === 'limpieza' && str_contains($words, ' blanquea') => 'blanqueamiento',
            $image === 'brackets' && (str_contains($words, ' control') || str_contains($words, ' ajuste')) => 'control',
            default => $image,
        };
    }

    public static function keyFor(Treatment $treatment): string
    {
        return self::key($treatment->name.' '.$treatment->slug);
    }

    /**
     * La imagen que corresponde a la especialidad que declara un doctor ("Implantólogo" → "implante"), o null
     * si su especialidad no es una de las que tienen imagen propia.
     */
    public static function specialty(?string $specialty): ?string
    {
        $image = self::key((string) $specialty);

        return in_array($image, self::SPECIALTIES, true) ? $image : null;
    }

    /**
     * La imagen de un tratamiento lista para la página, al tamaño en que se muestra (en píxeles de CSS).
     * Es un adorno junto al nombre: va sin texto alternativo.
     */
    public static function treatment(Treatment $treatment, int $size, bool $lazy = true): HtmlString
    {
        $key = self::keyFor($treatment);

        return new HtmlString(sprintf(
            '<img src="%1$s" srcset="%1$s 192w, %2$s 640w" sizes="%3$dpx" width="%3$d" height="%3$d" alt=""%4$s decoding="async">',
            e(self::url($key.'-192')),
            e(self::url($key)),
            $size,
            $lazy ? ' loading="lazy"' : '',
        ));
    }
}
