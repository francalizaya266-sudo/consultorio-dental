<?php

namespace App\Http\Controllers\Site;

use App\Enums\AppointmentStatus;
use App\Enums\PatientRelationship;
use App\Enums\Sex;
use App\Enums\TreatmentPlanStatus;
use App\Exceptions\BookingException;
use App\Filament\Resources\Patients\PatientResource;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Appointment;
use App\Models\MedicalBackground;
use App\Models\Patient;
use App\Models\RememberedDevice;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanInstallment;
use App\Services\Access\DeviceService;
use App\Services\Access\PatientAccess;
use App\Services\Billing\BillingService;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\PatientChangePolicy;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\StaffAlerts;
use App\Services\Web\ReturningPatient;
use App\Support\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
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
        $upcoming = $appointments()
            ->with('patient.medicalBackground')
            ->where('starts_at', '>=', now()->startOfDay())
            ->whereIn('status', [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress])
            ->oldest('starts_at')
            ->get();

        return view('site.account.home', [
            'account' => $account,
            'patients' => $patients,
            'upcoming' => $upcoming,
            // "Antes de tu cita": en la primera cita de quien todavía no tiene antecedentes.
            'askHealth' => $upcoming
                ->filter(fn (Appointment $appointment) => $appointment->patient->medicalBackground === null
                    && in_array($appointment->status, AppointmentStatus::changeable(), true))
                ->unique('patient_id')
                ->pluck('id')
                ->all(),
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

        if (($problem = Phone::problem($data['phone'])) !== null) {
            return back()->withInput()->withErrors(['phone' => $problem]);
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

    /**
     * "Pedir mi próxima sesión" desde la portada: quien está en tratamiento no reserva solo (su doctor programa las
     * sesiones), así que recepción recibe el pedido con su nombre y le escribe con el horario. Uno cada 12 horas.
     */
    public function requestSession(Request $request, Patient $patient, ReturningPatient $returning, StaffAlerts $alerts): RedirectResponse
    {
        $device = $this->device($request);
        abort_unless($device->account_id === $patient->account_id, 403);

        $row = $returning->for($device->account)['sessions']->firstWhere('patient.id', $patient->getKey());

        if ($row === null) {
            return back();
        }

        $key = ReturningPatient::sessionRequestKey($patient);

        if (! RateLimiter::tooManyAttempts($key, 1)) {
            RateLimiter::hit($key, 12 * 3600);
            $alerts->toReception(
                'Pide su próxima sesión',
                "{$patient->full_name} quiere agendar su próxima sesión (lleva {$row['done']} de {$row['total']}".($row['doctor'] ? ", con {$row['doctor']}" : '').'). Escríbele con el horario.',
                link: ['label' => 'Ver paciente', 'url' => PatientResource::getUrl('view', ['record' => $patient])],
            );
        }

        return back()->with('status', 'Listo: recepción ya sabe que '.($patient->relationship === PatientRelationship::Self ? 'quieres tu' : "{$patient->first_name} quiere su").' próxima sesión. Te escribiremos por WhatsApp con el horario.');
    }

    /**
     * "Antes de tu cita": el paciente cuenta su salud desde el celular recordado, para que el doctor lo sepa
     * antes de empezar. Solo mientras no haya antecedentes: lo ya registrado no se muestra ni se cambia desde aquí.
     */
    public function health(Request $request, Patient $patient): View|RedirectResponse
    {
        $this->authorizePatient($request, $patient);

        if ($patient->medicalBackground()->exists()) {
            return $this->healthAlreadyKnown($patient);
        }

        return view('site.account.health', [
            'patient' => $patient,
            'conditions' => MedicalBackground::CONDITIONS,
            'asksPregnancy' => self::asksPregnancy($patient),
            'asksSmoking' => ! self::isChild($patient),
        ]);
    }

    public function storeHealth(Request $request, Patient $patient): RedirectResponse
    {
        $this->authorizePatient($request, $patient);

        if ($patient->medicalBackground()->exists()) {
            return $this->healthAlreadyKnown($patient);
        }

        $data = $request->validate([
            'has_allergies' => ['required', 'in:no,yes'],
            'allergies' => ['nullable', 'required_if:has_allergies,yes', 'string', 'max:300'],
            'takes_medications' => ['required', 'in:no,yes'],
            'medications' => ['nullable', 'required_if:takes_medications,yes', 'string', 'max:300'],
            'conditions' => ['nullable', 'array'],
            'conditions.*' => [Rule::in(array_keys(MedicalBackground::CONDITIONS))],
            'notes' => ['nullable', 'string', 'max:500'],
        ], [
            'has_allergies.required' => 'Elige No o Sí.',
            'allergies.required_if' => 'Escribe a qué.',
            'takes_medications.required' => 'Elige No o Sí.',
            'medications.required_if' => 'Escribe cuáles.',
        ]);

        $asksPregnancy = self::asksPregnancy($patient);

        $patient->medicalBackground()->create([
            'allergies' => $data['has_allergies'] === 'yes' ? trim($data['allergies']) : 'Ninguna',
            'medications' => $data['takes_medications'] === 'yes' ? trim($data['medications']) : 'Ninguna',
            'conditions' => array_values($data['conditions'] ?? []),
            'is_pregnant' => $asksPregnancy && $request->boolean('is_pregnant'),
            'is_breastfeeding' => $asksPregnancy && $request->boolean('is_breastfeeding'),
            'smoker' => self::isChild($patient) ? null : $request->boolean('smoker'),
            'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
            'filled_by_patient_at' => now(),
        ]);

        return to_route('site.account')->with('status', 'Gracias. El doctor lo tendrá a la vista y lo repasará '.($patient->relationship === PatientRelationship::Self ? 'contigo' : "con {$patient->first_name}").' en la cita.');
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

    private function authorizePatient(Request $request, Patient $patient): void
    {
        abort_unless($this->device($request)->account_id === $patient->account_id, 403);
    }

    private function healthAlreadyKnown(Patient $patient): RedirectResponse
    {
        return to_route('site.account')->with('status', 'El consultorio ya tiene los antecedentes de '.$patient->first_name.'. Si algo cambió, cuéntaselo al doctor en la cita.');
    }

    /** Embarazo y lactancia se preguntan salvo a hombres y a niños. */
    private static function asksPregnancy(Patient $patient): bool
    {
        return $patient->sex !== Sex::Male && ! self::isChild($patient);
    }

    private static function isChild(Patient $patient): bool
    {
        return $patient->age !== null && $patient->age < 12;
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
