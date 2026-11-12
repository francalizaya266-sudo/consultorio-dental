<?php

namespace App\Exceptions;

use App\Models\Doctor;
use App\Models\WaitlistEntry;
use RuntimeException;

/** Error de reserva con un mensaje en español simple, listo para mostrar al usuario. */
class BookingException extends RuntimeException
{
    public static function slotTaken(): self
    {
        return new self('Ese horario se acaba de ocupar. Por favor elige otro.');
    }

    /** Para el personal, que ve la agenda completa: el horario elegido choca con algo. */
    public static function overlaps(): self
    {
        return new self('Ese horario se cruza con otra cita o con un bloqueo de la agenda. Elige otro.');
    }

    /** Para el personal: el horario está apartado para alguien de la lista de espera mientras responde. */
    public static function held(WaitlistEntry $hold): self
    {
        return new self("Ese horario está apartado para {$hold->patient->full_name} (lista de espera) hasta las {$hold->held_until->format('H:i')}, mientras responde. Si ya no lo quiere, libéralo en la lista de espera.");
    }

    public static function noRoomToday(Doctor $doctor): self
    {
        return new self("{$doctor->name} ya no tiene un hueco hoy para ese tratamiento. Elige otro doctor, o agéndalo con \"Nueva cita\" para otro día.");
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

    /** Para el personal: el paso que quería deshacer ya no es el último, o pasó más de un día. */
    public static function nothingToUndo(): self
    {
        return new self('Ya no hay nada que deshacer en esta cita.');
    }

    /** Para el personal: quiso devolver a la agenda una cita cancelada y su horario ya lo tomó otra. */
    public static function slotNoLongerFree(): self
    {
        return new self('El horario de esta cita ya se ocupó, así que no puede volver ahí. Agenda una cita nueva en otro horario.');
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
