<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Celulares en formato E.164. Por defecto se asume Perú (+51):
 * "987 654 321", "51987654321" y "+51 987-654-321" dan "+51987654321".
 */
final class Phone
{
    public static function normalize(string $phone, string $countryCode = '51'): string
    {
        $hasPlus = str_starts_with(trim($phone), '+');
        $digits = preg_replace('/\D+/', '', $phone);

        if ($digits === '') {
            throw new InvalidArgumentException('El número de celular está vacío.');
        }

        if (! $hasPlus && strlen($digits) === 9 && $countryCode === '51') {
            $digits = $countryCode.$digits;
        }

        if (strlen($digits) < 10 || strlen($digits) > 15) {
            throw new InvalidArgumentException("El número de celular {$phone} no es válido.");
        }

        return '+'.$digits;
    }

    public static function isValid(string $phone): bool
    {
        try {
            $normalized = self::normalize($phone);
        } catch (InvalidArgumentException) {
            return false;
        }

        // Celulares peruanos: 9 dígitos que empiezan con 9.
        return ! str_starts_with($normalized, '+51') || preg_match('/^\+519\d{8}$/', $normalized) === 1;
    }

    /** Número para enlaces wa.me (solo dígitos). */
    public static function forWhatsApp(string $phone): string
    {
        return ltrim(self::normalize($phone), '+');
    }

    /** "+51987654321" → "987 654 321" para mostrar en pantalla. */
    public static function display(string $phone): string
    {
        $normalized = self::normalize($phone);

        if (preg_match('/^\+51(\d{3})(\d{3})(\d{3})$/', $normalized, $m) === 1) {
            return "{$m[1]} {$m[2]} {$m[3]}";
        }

        return $normalized;
    }
}
