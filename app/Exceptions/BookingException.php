<?php

namespace App\Exceptions;

use RuntimeException;

/** Error de reserva con un mensaje en español simple, listo para mostrar al usuario. */
class BookingException extends RuntimeException
{
    public static function slotTaken(): self
    {
        return new self('Ese horario se acaba de ocupar. Por favor elige otro.');
    }

    public static function outsideWorkingHours(): self
    {
        return new self('Ese horario está fuera de la atención del doctor.');
    }

    public static function tooSoon(): self
    {
        return new self('Ese horario está muy cerca. Elige uno con más anticipación o llámanos.');
    }

    public static function tooFar(int $days): self
    {
        return new self("Por ahora solo se puede reservar hasta {$days} días adelante.");
    }

    public static function accountBlocked(): self
    {
        return new self('No podemos reservar desde la web para este número. Escríbenos por WhatsApp y te ayudamos.');
    }

    public static function patientLimit(int $max): self
    {
        return new self("Ya tienes {$max} citas próximas reservadas. Si necesitas otra, escríbenos por WhatsApp.");
    }

    public static function phoneLimit(int $max): self
    {
        return new self("Este celular ya tiene {$max} citas próximas reservadas. Escríbenos por WhatsApp para agendar más.");
    }

    public static function patientInTreatment(): self
    {
        return new self('Como estás en tratamiento, tu doctor programa tus próximas sesiones. Escríbenos si necesitas un cambio.');
    }

    public static function treatmentNotBookableOnline(): self
    {
        return new self('Este tratamiento se agenda después de una evaluación. Puedes reservar una evaluación.');
    }

    public static function doctorDoesNotOfferTreatment(): self
    {
        return new self('Este doctor no realiza ese tratamiento.');
    }

    public static function notChangeable(): self
    {
        return new self('Esta cita ya no se puede cambiar.');
    }

    public static function tooLateToCancel(int $hours): self
    {
        return new self("Faltan menos de {$hours} horas para tu cita. Para cancelarla, comunícate con el consultorio.");
    }

    public static function tooManyOptions(int $max): self
    {
        return new self("Puedes proponer hasta {$max} horarios.");
    }
}
