<?php

namespace App\Http\Controllers\Site;

use App\Enums\AppointmentStatus;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\RememberedDevice;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanInstallment;
use App\Services\Access\DeviceService;
use App\Services\Access\PatientAccess;
use App\Services\Billing\BillingService;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\PatientChangePolicy;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Mi cuenta: desde el celular recordado se ven y manejan las próximas citas de toda la
 * familia. Planes y pagos, citas pasadas y datos personales piden un enlace nuevo (verificación reforzada).
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly PatientAccess $access,
        private readonly DeviceService $devices,
        private readonly AppointmentService $appointments,
        private readonly PatientChangePolicy $policy,
        private readonly BillingService $billing,
        private readonly TreatmentPlanService $plans,
    ) {}

    public function show(Request $request): View
    {
        $device = $this->access->device($request);

        if ($device === null) {
            return view('site.account.guest');
        }

        $account = $device->account;
        $elevated = $this->devices->isElevated();
        $patients = $account->patients()->orderBy('first_name')->get();
        $appointments = fn () => Appointment::query()
            ->whereHas('patient', fn ($query) => $query->whereBelongsTo($account))
            ->with(['patient', 'doctor', 'treatment']);

        return view('site.account.home', [
            'account' => $account,
            'patients' => $patients,
            'upcoming' => $appointments()
                ->where('starts_at', '>=', now()->startOfDay())
                ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress])
                ->oldest('starts_at')
                ->get(),
            'past' => $elevated ? $appointments()->where('starts_at', '<', now())->latest('starts_at')->limit(20)->get() : null,
            'plans' => $elevated ? $this->plansOf($account) : null,
            'balances' => $elevated ? $patients->mapWithKeys(fn ($patient) => [$patient->full_name => $patient->balance()])->reject(fn (float $balance) => $balance === 0.0) : null,
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

        return back()->with('status', 'Te enviaremos un enlace por WhatsApp. Al tocarlo, verás tus planes, pagos, citas anteriores y datos durante unos minutos.');
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

    /**
     * Los planes que la familia aceptó, con lo hecho, lo pagado y la próxima cuota. Los terminados y
     * pagados ya no se muestran.
     *
     * @return Collection<int, array{plan: TreatmentPlan, progress: array{total: float, paid: float, owed: float, remaining: float, late: bool, next: array{installment: TreatmentPlanInstallment, due: float}|null}|null, payment: ?string}>
     */
    private function plansOf(Account $account): Collection
    {
        return TreatmentPlan::query()
            ->whereHas('patient', fn ($query) => $query->whereBelongsTo($account))
            ->whereNotNull('accepted_at')
            ->whereNot('status', TreatmentPlanStatus::Cancelled)
            ->with(['patient', 'doctor', 'items', 'installments', 'includedIn.patient'])
            ->latest('accepted_at')
            ->get()
            ->map(fn (TreatmentPlan $plan) => [
                'plan' => $plan,
                // En un 2x1, la otra persona no paga: su plan va incluido en el de quien paga.
                'progress' => $plan->included_in_plan_id ? null : $this->billing->planProgress($plan),
                'payment' => $plan->included_in_plan_id ? null : $this->plans->paymentText($plan),
            ])
            ->reject(fn (array $row) => $row['plan']->status === TreatmentPlanStatus::Completed && ($row['progress']['remaining'] ?? 0) <= 0)
            ->values();
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
