<?php

namespace App\Http\Controllers\Site;

use App\Enums\AppointmentStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\RememberedDevice;
use App\Services\Access\DeviceService;
use App\Services\Access\PatientAccess;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\PatientChangePolicy;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Mi cuenta: desde el celular recordado se ven y manejan las próximas citas de toda la
 * familia. Citas pasadas y datos personales piden un enlace nuevo (verificación reforzada).
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly PatientAccess $access,
        private readonly DeviceService $devices,
        private readonly AppointmentService $appointments,
        private readonly PatientChangePolicy $policy,
    ) {}

    public function show(Request $request): View
    {
        $device = $this->access->device($request);

        if ($device === null) {
            return view('site.account.guest');
        }

        $account = $device->account;
        $elevated = $this->devices->isElevated();
        $appointments = fn () => Appointment::query()
            ->whereHas('patient', fn ($query) => $query->whereBelongsTo($account))
            ->with(['patient', 'doctor', 'treatment']);

        return view('site.account.home', [
            'account' => $account,
            'patients' => $account->patients()->orderBy('first_name')->get(),
            'upcoming' => $appointments()
                ->where('starts_at', '>=', now()->startOfDay())
                ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress])
                ->oldest('starts_at')
                ->get(),
            'past' => $elevated ? $appointments()->where('starts_at', '<', now())->latest('starts_at')->limit(20)->get() : null,
            'elevated' => $elevated,
            'policy' => $this->policy,
        ]);
    }

    /** "Pedir acceso" desde un celular nuevo: recepción le envía su enlace por WhatsApp. */
    public function requestAccess(Request $request): RedirectResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:20']], attributes: ['phone' => 'Celular']);

        if (! Phone::isValid($data['phone'])) {
            return back()->withInput()->withErrors(['phone' => 'Escribe tu celular de 9 dígitos, por ejemplo 987 654 321.']);
        }

        if (! $this->throttle('acceso:'.$request->ip(), 5) || ! $this->throttle('acceso:'.Phone::normalize($data['phone']), 2)) {
            return back()->with('error', 'Ya recibimos tu pedido. Te escribiremos pronto por WhatsApp.');
        }

        $this->access->requestAccess($data['phone']);

        return back()->with('access_requested', Phone::normalize($data['phone']));
    }

    /** Desde el celular recordado: pedir el enlace para ver citas pasadas y datos personales. */
    public function requestVerification(Request $request): RedirectResponse
    {
        $device = $this->device($request);

        if ($this->throttle('verificar:'.$device->account_id, 2)) {
            $this->access->requestAccess($device->account->phone);
        }

        return back()->with('status', 'Te enviaremos un enlace por WhatsApp. Al tocarlo, verás tus citas anteriores y tus datos durante unos minutos.');
    }

    public function confirm(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);

        if ($appointment->status === AppointmentStatus::Pending && $this->policy->canChange($appointment)) {
            $this->appointments->confirmByPatient($appointment);
        }

        return back()->with('status', '¡Listo! La cita de '.$appointment->patient->first_name.' quedó confirmada.');
    }

    public function cancel(Request $request, Appointment $appointment): RedirectResponse
    {
        $this->authorizeAppointment($request, $appointment);

        try {
            $this->appointments->cancelByPatient($appointment, $request->string('reason')->limit(250)->toString() ?: null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', 'La cita fue cancelada.');
    }

    public function change(Request $request, Appointment $appointment): View
    {
        $this->authorizeAppointment($request, $appointment);

        return view('site.change', ['appointment' => $appointment, 'token' => null]);
    }

    public function logout(Request $request): RedirectResponse
    {
        $this->devices->forget($request);

        return to_route('site.account')->with('status', 'Listo. Este celular ya no está recordado.');
    }

    private function device(Request $request): RememberedDevice
    {
        return $this->access->device($request) ?? abort(403);
    }

    private function authorizeAppointment(Request $request, Appointment $appointment): void
    {
        abort_unless($this->access->canAccess($request, $appointment), 403);
    }

    /** true si todavía puede intentarlo (y cuenta el intento). */
    private function throttle(string $key, int $perHour): bool
    {
        if (RateLimiter::tooManyAttempts($key, $perHour)) {
            return false;
        }

        RateLimiter::hit($key, 3600);

        return true;
    }
}
