<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Avisos de WhatsApp que prepara el sistema. Los textos se editan en el panel. */
enum MessageTemplateKey: string implements HasLabel
{
    case BookingConfirmation = 'booking_confirmation';
    case AppointmentScheduled = 'appointment_scheduled';
    case Reminder = 'appointment_reminder';
    case Rescheduled = 'appointment_rescheduled';
    case Cancelled = 'appointment_cancelled';
    case RescheduleApproved = 'reschedule_approved';
    case RescheduleRejected = 'reschedule_rejected';
    case AccessLink = 'access_link';
    case Prescription = 'prescription';
    case TreatmentPlan = 'treatment_plan';
    case WaitlistOffer = 'waitlist_offer';

    public function getLabel(): string
    {
        return match ($this) {
            self::BookingConfirmation => 'Confirmación de reserva web',
            self::AppointmentScheduled => 'Cita programada por el consultorio',
            self::Reminder => 'Recordatorio del día anterior',
            self::Rescheduled => 'Cambio de horario',
            self::Cancelled => 'Cita cancelada por el consultorio',
            self::RescheduleApproved => 'Solicitud de cambio aprobada',
            self::RescheduleRejected => 'Solicitud de cambio no aprobada',
            self::AccessLink => 'Enlace de acceso a Mi cuenta',
            self::Prescription => 'Receta e indicaciones',
            self::TreatmentPlan => 'Plan de tratamiento (presupuesto)',
            self::WaitlistOffer => 'Horario libre para lista de espera',
        };
    }

    /** Texto inicial; el administrador puede cambiarlo desde el panel. */
    public function defaultBody(): string
    {
        return match ($this) {
            self::BookingConfirmation => "Hola {nombre} 👋, te escribimos de {consultorio}.\nTu cita de {tratamiento} quedó reservada para el {fecha} a las {hora} con {doctor}.\n{precio}\n\nConfirma tu asistencia, cambia el horario o cancela aquí:\n{enlace}",
            self::AppointmentScheduled => "Hola {nombre}, te escribimos de {consultorio}.\nTu próxima cita de {tratamiento} es el {fecha} a las {hora} con {doctor}.\n\nPuedes confirmarla aquí:\n{enlace}",
            self::Reminder => "Hola {nombre}, te recordamos tu cita de mañana {fecha} a las {hora} con {doctor} en {consultorio}.\n\nConfirma tu asistencia aquí:\n{enlace}",
            self::Rescheduled => "Hola {nombre}, tu cita de {tratamiento} cambió al {fecha} a las {hora} con {doctor}.\n\nConfirma el nuevo horario aquí:\n{enlace}",
            self::Cancelled => 'Hola {nombre}, lamentamos avisarte que tu cita del {fecha} a las {hora} fue cancelada. Escríbenos y te ayudamos a elegir otro horario.',
            self::RescheduleApproved => "Hola {nombre}, aprobamos tu cambio. Tu cita de {tratamiento} ahora es el {fecha} a las {hora} con {doctor}.\n\nDetalles aquí:\n{enlace}",
            self::RescheduleRejected => 'Hola {nombre}, no pudimos mover tu cita a los horarios que propusiste. Tu cita sigue el {fecha} a las {hora}. Escríbenos y buscamos otra opción.',
            self::AccessLink => "Hola, este es tu enlace para entrar a Mi cuenta en {consultorio}:\n{enlace}\n\nNo lo compartas con nadie.",
            self::Prescription => "Hola {nombre}, te enviamos tu receta e indicaciones de {doctor}:\n\n{receta}",
            self::TreatmentPlan => "Hola {nombre}, te enviamos tu plan de tratamiento con {doctor}:\n\n{plan}\n\nTotal: {total}\nEs un presupuesto referencial: si en el camino el tratamiento cambia, te lo explicamos antes. Cualquier duda, escríbenos.",
            self::WaitlistOffer => 'Hola {nombre}, se liberó un horario para {tratamiento} el {fecha} a las {hora} con {doctor}. ¿Lo quieres? Respóndenos y te lo reservamos.',
        };
    }
}
