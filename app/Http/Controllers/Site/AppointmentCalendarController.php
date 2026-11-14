<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Services\Web\ClinicInfo;
use App\Support\CalendarEvent;
use Illuminate\Http\Response;

/**
 * "Guardar en mi calendario" al terminar de reservar: la cita como archivo .ics. La dirección va firmada,
 * así que solo la tiene quien acaba de reservar; no hace falta el celular recordado.
 */
class AppointmentCalendarController extends Controller
{
    public function __invoke(Appointment $appointment, ClinicInfo $clinic): Response
    {
        abort_unless($appointment->status->isChangeable(), 404);

        return response(CalendarEvent::forAppointment($appointment->load(['patient', 'doctor', 'treatment']), $clinic), 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="cita.ics"',
        ]);
    }
}
