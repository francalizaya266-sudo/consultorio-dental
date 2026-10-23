<?php

namespace App\Http\Controllers\Patient;

use App\Enums\RescheduleRequestStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\MagicLink;
use App\Services\Access\MagicLinkService;
use App\Services\Access\PatientAccess;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\PatientChangePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Enlace personal que llega por WhatsApp. El de una cita la muestra para confirmarla,
 * cambiarla o cancelarla; el de acceso abre Mi cuenta. Abrir el enlace no cambia nada
 * (la vista previa de WhatsApp también lo abre): solo los botones actúan.
 */
class AppointmentLinkController extends Controller
{
    public function __construct(
        private readonly MagicLinkService $links,
        private readonly PatientAccess $access,
        private readonly AppointmentService $appointments,
        private readonly PatientChangePolicy $policy,
    ) {}

    public function show(string $token): View
    {
        $link = $this->links->find($token);

        if ($link === null) {
            return view('patient.link-expired');
        }

        if ($link->appointment === null) {
            return view('site.account.enter', ['token' => $token]);
        }

        $appointment = $link->appointment->load(['patient', 'doctor', 'treatment']);

        return view('patient.appointment', [
            'token' => $token,
            'appointment' => $appointment,
            'canChange' => $this->policy->canChange($appointment),
            'canCancel' => $this->policy->canSelfCancel($appointment),
            'cancelLimitHours' => $this->policy->selfCancelMinHours(),
            'pendingRequest' => $appointment->rescheduleRequests()->where('status', RescheduleRequestStatus::Pending)->with('options')->latest('id')->first(),
        ]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        $link = $this->act($request, $token);
        $this->appointments->confirmByPatient($link->appointment);

        return to_route('patient.link', $token)->with('status', '¡Listo! Tu asistencia quedó confirmada.');
    }

    public function cancel(Request $request, string $token): RedirectResponse
    {
        $link = $this->act($request, $token);

        try {
            $this->appointments->cancelByPatient($link->appointment, $request->string('reason')->limit(250)->toString() ?: null);
        } catch (BookingException $e) {
            return to_route('patient.link', $token)->with('error', $e->getMessage());
        }

        return to_route('patient.link', $token)->with('status', 'Tu cita fue cancelada. Cuando quieras, puedes reservar otra.');
    }

    public function change(string $token): View
    {
        $link = $this->links->find($token);
        abort_if($link?->appointment === null, 404);

        return view('site.change', ['appointment' => $link->appointment, 'token' => $token]);
    }

    /** Enlace de acceso: el paciente tocó "Entrar" y este celular queda recordado. */
    public function enter(Request $request, string $token): RedirectResponse
    {
        $link = $this->links->find($token);
        abort_if($link === null || $link->appointment_id !== null, 404);

        $this->access->actWithLink($request, $link);

        return to_route('site.account')->with('status', '¡Listo! Ya puedes ver tus citas. Este celular quedará recordado.');
    }

    private function act(Request $request, string $token): MagicLink
    {
        $link = $this->links->find($token);
        abort_if($link?->appointment === null, 404);

        $this->access->actWithLink($request, $link);

        return $link;
    }
}
