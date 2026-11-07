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
    case InstallmentReminder = 'installment_reminder';
    case InstallmentOverdue = 'installment_overdue';
    case PlanFollowUp = 'plan_follow_up';
    case PlanNextSession = 'plan_next_session';
    case CheckupRecall = 'checkup_recall';
    case Birthday = 'birthday';
    case PaymentReceipt = 'payment_receipt';
    case ReviewRequest = 'review_request';

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
            self::InstallmentReminder => 'Aviso de cuota por vencer',
            self::InstallmentOverdue => 'Cuota atrasada',
            self::PlanFollowUp => 'Presupuesto sin respuesta',
            self::PlanNextSession => 'Plan sin próxima cita',
            self::CheckupRecall => 'Le toca control',
            self::Birthday => 'Saludo de cumpleaños',
            self::PaymentReceipt => 'Constancia de pago',
            self::ReviewRequest => 'Pedido de opinión en Google',
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
            self::WaitlistOffer => 'Hola {nombre}, se liberó un horario para {tratamiento} el {fecha} a las {hora} con {doctor}. Te lo guardamos hasta las {hasta}: ¿lo quieres? Respóndenos y te lo reservamos.',
            self::InstallmentReminder => "Hola {nombre}, te escribimos de {consultorio}.\nEl {fecha} vence tu {cuota} de {plan}: {monto}.\n\nPuedes pagarla en el consultorio con Yape, Plin, tarjeta o efectivo. Si ya la pagaste, no te preocupes.",
            self::InstallmentOverdue => "Hola {nombre}, te escribimos de {consultorio}.\nTu {cuota} de {plan} venció el {fecha} y queda {monto} por pagar.\n\nPuedes pagarla en el consultorio con Yape, Plin, tarjeta o efectivo. Si ya la pagaste, avísanos y lo revisamos.",
            self::PlanFollowUp => "Hola {nombre}, te escribimos de {consultorio}.\n¿Pudiste revisar tu plan de tratamiento con {doctor}? Si tienes dudas sobre el tratamiento o las formas de pago, escríbenos y te ayudamos.\n\nCuando quieras, te reservamos la primera cita.",
            self::PlanNextSession => "Hola {nombre}, te escribimos de {consultorio}.\nEn tu tratamiento queda pendiente: {pendiente}.\n\n¿Te reservamos tu próxima cita? Respóndenos con el día y la hora que te acomodan.",
            self::CheckupRecall => "Hola {nombre}, te escribimos de {consultorio}.\nYa es momento de tu {control}. ¿Te reservamos una cita? Respóndenos con el día y la hora que te acomodan.",
            self::Birthday => '¡Feliz cumpleaños, {nombre}! 🎂 Todo el equipo de {consultorio} te desea un gran día.',
            self::PaymentReceipt => "Hola {nombre}, te escribimos de {consultorio}.\nRecibimos tu pago de {monto} ({medio}) el {fecha}. Constancia {numero}:\n{detalle}\n\nSaldo: {saldo}. ¡Gracias!",
            self::ReviewRequest => "Hola {nombre}, gracias por confiar en {consultorio}. Si te sentiste bien atendido, ¿nos dejas tu opinión en Google? Toma un minuto y ayuda a otras personas a encontrarnos:\n{opinion}",
        };
    }
}
