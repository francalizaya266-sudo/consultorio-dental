<?php

namespace App\Http\Controllers\Patient;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\MagicLink;
use App\Services\Access\DeviceService;
use App\Services\Access\MagicLinkService;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\PatientChangePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Enlace personal que llega por WhatsApp: el paciente ve su cita y la
 * confirma o cancela con un toque. Abrir el enlace no cambia nada (la vista
 * previa de WhatsApp también lo abre); solo los botones actúan.
 */
class AppointmentLinkController extends Controller
{
    public function __construct(
        private readonly MagicLinkService $links,
        private readonly DeviceService $devices,
        private readonly AppointmentService $appointments,
        private readonly PatientChangePolicy $policy,
    ) {}

    public function show(string $token): View
    {
        $link = $this->links->find($token);

        if ($link?->appointment === null) {
            return view('patient.link-expired');
        }

        $appointment = $link->appointment->load(['patient', 'doctor', 'treatment']);

        return view('patient.appointment', [
            'token' => $token,
            'appointment' => $appointment,
            'canChange' => $this->policy->canChange($appointment),
            'canCancel' => $this->policy->canSelfCancel($appointment),
            'cancelLimitHours' => $this->policy->selfCancelMinHours(),
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

    /** El paciente tocó un botón: se verifica el celular y se recuerda este dispositivo. */
    private function act(Request $request, string $token): MagicLink
    {
        $link = $this->links->find($token);
        abort_if($link?->appointment === null, 404);

        $this->links->consume($link);

        if ($this->devices->current($request)?->account_id !== $link->account_id) {
            $this->devices->remember($link->account, $request);
        }

        $this->devices->elevate($request);

        return $link;
    }
}
