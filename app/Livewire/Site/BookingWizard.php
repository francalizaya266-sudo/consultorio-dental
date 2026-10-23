<?php

namespace App\Livewire\Site;

use App\Enums\AppointmentOrigin;
use App\Enums\PatientRelationship;
use App\Exceptions\BookingException;
use App\Livewire\Site\Concerns\PicksSlots;
use App\Models\Appointment;
use App\Models\Campaign;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\RememberedDevice;
use App\Models\Setting;
use App\Models\Treatment;
use App\Services\Access\DeviceService;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\WebAvailability;
use App\Services\Booking\WebBookingData;
use App\Support\Dates;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Reserva en la web en pocos pasos: qué necesita, día y hora, sus datos y listo.
 * Desde un dispositivo recordado no pide el celular y deja elegir al familiar.
 */
class BookingWizard extends Component
{
    use PicksSlots;

    #[Locked]
    public int $step = 1;

    #[Locked]
    public ?string $time = null;

    /** Familiar ya registrado (solo desde un dispositivo recordado); null = otra persona. */
    #[Locked]
    public ?int $patientId = null;

    public string $phone = '';

    public string $firstName = '';

    public string $lastName = '';

    /** "me" (para mí) o "family" (un familiar a cargo). */
    public string $who = 'me';

    /** "self" o el parentesco del familiar a cargo. */
    public string $relationship = 'self';

    public ?string $birthDate = null;

    public ?string $origin = null;

    public bool $acceptsPrivacy = false;

    public bool $acceptsPromotions = false;

    public string $promoCode = '';

    public string $note = '';

    #[Locked]
    public ?int $appointmentId = null;

    /** Doctor elegido desde su ficha en la web: se usa en cuanto elige un tratamiento que atiende. */
    #[Locked]
    public ?int $preferredDoctorId = null;

    #[Locked]
    public ?string $error = null;

    public function mount(): void
    {
        $this->promoCode = strtoupper(substr((string) request('codigo'), 0, 30));
        $this->origin = AppointmentOrigin::tryFrom((string) request('origen'))?->value;

        $this->preferredDoctorId = filled(request('doctor')) ? Doctor::query()->where('slug', (string) request('doctor'))->value('id') : null;
        $campaign = $this->promoCode ? Campaign::query()->published()->where('promo_code', $this->promoCode)->first() : null;
        $treatment = $this->treatments->firstWhere('slug', (string) request('tratamiento'))
            ?? ($campaign ? $this->treatments->firstWhere('id', $campaign->treatment_id) : null);

        if ($treatment) {
            $this->chooseTreatment($treatment->getKey());

            // Desde "Próximo horario libre": el día y la hora ya elegidos. Si ese día ya no
            // se ofrece, la hora tampoco: que no quede elegida en otro día sin que lo note.
            $this->selectDate((string) request('fecha'));

            if (filled(request('hora')) && $this->date === request('fecha')) {
                $this->chooseTime((string) request('hora'));
            }
        }

        if ($device = $this->device) {
            $this->patientId = $device->account->holder?->getKey() ?? $this->family->first()?->getKey();
        }
    }

    /** @return Collection<int, Treatment> */
    #[Computed]
    public function treatments(): Collection
    {
        return app(WebAvailability::class)->treatments();
    }

    #[Computed]
    public function device(): ?RememberedDevice
    {
        return app(DeviceService::class)->current(request());
    }

    /** @return Collection<int, Patient> Pacientes a cargo del celular recordado. */
    #[Computed]
    public function family(): Collection
    {
        return $this->device?->account->patients()->orderByRaw('relationship = ? desc', [PatientRelationship::Self->value])->orderBy('first_name')->get() ?? collect();
    }

    #[Computed]
    public function appointment(): ?Appointment
    {
        return $this->appointmentId ? Appointment::with(['patient', 'doctor', 'treatment'])->find($this->appointmentId) : null;
    }

    /** Hay un titular registrado en el celular recordado: "otra persona" es un familiar. */
    #[Computed]
    public function hasHolder(): bool
    {
        return $this->family->contains('relationship', PatientRelationship::Self);
    }

    public function choosePatient(int $patientId): void
    {
        if ($this->family->contains('id', $patientId)) {
            $this->patientId = $patientId;
        }
    }

    public function forSomeoneElse(): void
    {
        $this->patientId = null;
        $this->who = $this->hasHolder ? 'family' : 'me';
        $this->updatedWho();
    }

    public function updatedWho(): void
    {
        $this->relationship = $this->who === 'family' ? PatientRelationship::Child->value : PatientRelationship::Self->value;
    }

    public function chooseTreatment(int $treatmentId): void
    {
        if (! $this->treatments->contains('id', $treatmentId)) {
            return;
        }

        $this->treatmentId = $treatmentId;
        $this->time = null;
        $this->error = null;
        unset($this->treatment, $this->doctors);
        $this->selectDoctor($this->preferredDoctorId);
        $this->step = 2;
    }

    public function chooseTime(string $time): void
    {
        if (! $this->daySlots->contains('time', $time)) {
            return;
        }

        $this->time = $time;
        $this->error = null;
        $this->step = 3;
    }

    public function back(): void
    {
        $this->error = null;
        $this->step = max(1, $this->step - 1);

        if ($this->step === 2) {
            $this->refreshDays();
        }
    }

    public function book(AppointmentService $appointments): void
    {
        if ($this->step !== 3 || ! $this->treatment || ! $this->date || ! $this->time) {
            return;
        }

        $this->validate();

        $limiter = 'reserva-web:'.request()->ip();

        if (RateLimiter::tooManyAttempts($limiter, 8)) {
            $this->error = 'Hiciste varias reservas seguidas. Espera un rato o escríbenos por WhatsApp.';

            return;
        }

        $slot = $this->resolveSlot($this->date, $this->time);

        if ($slot === null) {
            $this->backToTimes(BookingException::slotTaken()->getMessage());

            return;
        }

        [$startsAt, $doctor] = $slot;
        $existing = $this->patientId ? $this->family->firstWhere('id', $this->patientId) : null;

        try {
            $appointment = $appointments->bookFromWeb(new WebBookingData(
                doctorId: $doctor->getKey(),
                treatmentId: $this->treatmentId,
                startsAt: $startsAt,
                phone: $this->device ? $this->device->account->phone : $this->phone,
                patientId: $existing?->getKey(),
                firstName: $existing ? null : $this->firstName,
                lastName: $existing ? null : $this->lastName,
                birthDate: ! $existing && $this->birthDate ? CarbonImmutable::parse($this->birthDate) : null,
                relationship: $this->patientRelationship(),
                acceptsPrivacy: $this->acceptsPrivacy || $this->privacyAlreadyAccepted(),
                acceptsPromotions: $this->acceptsPromotions,
                origin: AppointmentOrigin::tryFrom((string) $this->origin),
                promoCode: filled($this->promoCode) ? $this->promoCode : null,
                note: filled($this->note) ? $this->note : null,
            ), $this->device);
        } catch (BookingException $e) {
            $e->getMessage() === BookingException::slotTaken()->getMessage()
                ? $this->backToTimes($e->getMessage())
                : $this->error = $e->getMessage();

            return;
        } catch (AuthorizationException $e) {
            $this->error = $e->getMessage();

            return;
        }

        RateLimiter::hit($limiter, 3600);

        $this->appointmentId = $appointment->getKey();
        $this->error = null;
        $this->step = 4;
    }

    protected function rules(): array
    {
        $newPerson = $this->patientId === null;

        return [
            'phone' => [Rule::requiredIf($this->device === null), 'nullable', 'string', 'max:20', function (string $attribute, mixed $value, \Closure $fail) {
                if ($this->device === null && ! Phone::isValid((string) $value)) {
                    $fail('Escribe tu celular de 9 dígitos, por ejemplo 987 654 321.');
                }
            }],
            'firstName' => [Rule::requiredIf($newPerson), 'nullable', 'string', 'max:80'],
            'lastName' => [Rule::requiredIf($newPerson), 'nullable', 'string', 'max:80'],
            'relationship' => ['required', Rule::enum(PatientRelationship::class)],
            'birthDate' => ['nullable', 'date', 'before:today', 'after:1900-01-01'],
            'origin' => ['nullable', Rule::enum(AppointmentOrigin::class)],
            'acceptsPrivacy' => [$this->privacyAlreadyAccepted() ? 'boolean' : 'accepted'],
            'promoCode' => ['nullable', 'string', 'max:30'],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }

    protected function validationAttributes(): array
    {
        return [
            'phone' => 'Celular',
            'firstName' => 'Nombres',
            'lastName' => 'Apellidos',
            'relationship' => 'Para quién',
            'birthDate' => 'Fecha de nacimiento',
            'note' => 'Comentario',
        ];
    }

    protected function messages(): array
    {
        return [
            'acceptsPrivacy.accepted' => 'Para reservar, acepta la política de privacidad.',
            'birthDate.before' => 'Revisa la fecha de nacimiento.',
        ];
    }

    /** Para mí (si el celular aún no tiene titular) o el parentesco elegido. */
    private function patientRelationship(): PatientRelationship
    {
        $relationship = PatientRelationship::tryFrom($this->relationship) ?? PatientRelationship::Child;

        if ($this->who === 'me' && ! $this->hasHolder) {
            return PatientRelationship::Self;
        }

        return $relationship === PatientRelationship::Self ? PatientRelationship::Other : $relationship;
    }

    public function privacyAlreadyAccepted(): bool
    {
        return $this->device?->account->privacy_accepted_at !== null;
    }

    /** Enlace para escribir al consultorio con la reserva ya contada. */
    public function whatsappUrl(): ?string
    {
        $number = (string) Setting::get('clinic.whatsapp');
        $appointment = $this->appointment;

        if (! $appointment || ! Phone::isValid($number)) {
            return null;
        }

        $text = "Hola, acabo de reservar {$appointment->treatment->name} para el "
            .mb_strtolower(Dates::day($appointment->starts_at->toImmutable()))
            .' a las '.Dates::time($appointment->starts_at->format('H:i'))
            ." a nombre de {$appointment->patient->full_name}.";

        return 'https://wa.me/'.Phone::forWhatsApp($number).'?text='.rawurlencode($text);
    }

    private function backToTimes(string $message): void
    {
        $this->time = null;
        $this->step = 2;
        $this->refreshDays();
        $this->error = $message;
    }

    public function render(): View
    {
        return view('livewire.site.booking-wizard', [
            'relationships' => collect(PatientRelationship::cases())->reject(fn (PatientRelationship $r) => $r === PatientRelationship::Self),
            'origins' => collect(AppointmentOrigin::cases())->reject(fn (AppointmentOrigin $o) => in_array($o, [AppointmentOrigin::Campaign, AppointmentOrigin::Referral], true)),
        ]);
    }
}
