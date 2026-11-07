<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de documento. */
enum DocumentType: string implements HasLabel
{
    case Dni = 'dni';
    case ForeignerCard = 'ce';
    case Passport = 'passport';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dni => 'DNI',
            self::ForeignerCard => 'Carné de extranjería',
            self::Passport => 'Pasaporte',
            self::Other => 'Otro',
        };
    }

    /** Cómo se escribe un número válido (sin espacios ni guiones), o null si no se revisa. */
    public function pattern(): ?string
    {
        return match ($this) {
            self::Dni => '/^\d{8}$/',
            self::ForeignerCard => '/^[A-Z0-9]{8,12}$/i',
            self::Passport => '/^[A-Z0-9]{6,12}$/i',
            self::Other => null,
        };
    }

    public function invalidMessage(): string
    {
        return match ($this) {
            self::Dni => 'El DNI tiene 8 dígitos.',
            self::ForeignerCard => 'El carné de extranjería tiene de 8 a 12 letras o números.',
            self::Passport => 'El pasaporte tiene de 6 a 12 letras o números.',
            self::Other => 'Revisa el número de documento.',
        };
    }

    /** Sin espacios, puntos ni guiones, como se guarda: " 0765-4321 " → "07654321". */
    public static function clean(?string $number): ?string
    {
        $number = preg_replace('/[\s.\-]+/', '', (string) $number);

        return $number === '' ? null : mb_strtoupper($number);
    }
}
