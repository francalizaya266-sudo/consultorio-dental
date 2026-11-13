<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Dónde aparece un doctor o un tratamiento: una sola pregunta en vez de dos interruptores que se pisaban
 * ("Aparece en la web" y "Recibe reservas desde la web"). Cada nivel incluye al anterior.
 */
enum WebPresence: string implements HasLabel
{
    case Clinic = 'clinic';
    case Web = 'web';
    case Booking = 'booking';

    /** Lo que dejaban los interruptores sueltos: se reserva en línea sin salir en la página. Solo para lo ya guardado así. */
    case BookingOnly = 'booking_only';

    /** El nivel que corresponde a lo guardado en los dos interruptores de antes. */
    public static function fromFlags(bool $public, bool $bookable): self
    {
        return match (true) {
            $public && $bookable => self::Booking,
            $public => self::Web,
            $bookable => self::BookingOnly,
            default => self::Clinic,
        };
    }

    /**
     * Los niveles que se ofrecen al elegir: los tres de siempre y, solo si la ficha ya estaba así, el de antes.
     *
     * @return list<self>
     */
    public static function choices(?self $current = null): array
    {
        return [self::Clinic, self::Web, self::Booking, ...($current === self::BookingOnly ? [self::BookingOnly] : [])];
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::Clinic => 'Solo en el consultorio',
            self::Web => 'También en la página web',
            self::Booking => 'En la página web y con reserva en línea',
            self::BookingOnly => 'Solo reserva en línea, sin salir en la página',
        };
    }

    /** Para una columna angosta: "Consultorio", "Web", "Web y reserva". */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Clinic => 'Solo consultorio',
            self::Web => 'Página web',
            self::Booking => 'Web y reserva',
            self::BookingOnly => 'Solo reserva',
        };
    }

    /** Sale en la página web. */
    public function isPublic(): bool
    {
        return in_array($this, [self::Web, self::Booking], true);
    }

    /** El paciente puede reservarlo por su cuenta desde la web. */
    public function isBookable(): bool
    {
        return in_array($this, [self::Booking, self::BookingOnly], true);
    }
}
