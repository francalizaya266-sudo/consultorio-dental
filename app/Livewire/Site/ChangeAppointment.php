<?php

namespace App\Livewire\Site;

use App\Enums\RescheduleRequestStatus;
use App\Exceptions\BookingException;
use App\Livewire\Site\Concerns\PicksSlots;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\RescheduleRequest;
use App\Models\Setting;
use App\Services\Access\MagicLinkService;
use App\Services\Access\PatientAccess;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\PatientChangePolicy;
use App\Services\Booking\RescheduleMode;
use App\Services\Booking\RescheduleRequestService;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Cambiar el horario de una cita desde el enlace o desde Mi cuenta.
 * Nivel 1: el paciente elige otro horario y se cambia solo. Nivel 2: propone
 * hasta 3 horarios y el consultorio aprueba uno.
 */
class ChangeAppointment extends Component
{
    use PicksSlots;

    #[Locked]
    public int $appointmentId;

    /** Enlace de la cita, si entró por WhatsApp; si no, entra con su celular recordado. */
    #[Locked]
    public ?string $token = null;

    /** @var list<string> Horarios elegidos: "Y-m-d H:i". */
    #[Locked]
    public array $chosen = [];

    public string $reason = '';

    #[Locked]
    public ?string $error = null;

    public function mount(Appointment $appointment, ?string $token = null): void
    {
        $this->appointmentId = $appointment->getKey();
        $this->token = $token;
        $this->treatmentId = $appointment->treatment_id;
        $this->doctorId = $appointment->doctor_id;

        abort_unless($this->appointment !== null, 403);

        $this->refreshDays();
    }

    /** La cita, solo si esta petición tiene acceso a ella (se revisa en cada clic). */
    #[Computed]
    public function appointment(): ?Appointment
    {
        $appointment = Appointment::with(['patient', 'doctor', 'treatment'])->find($this->appointmentId);

        return $appointment && app(PatientAccess::class)->canAccess(request(), $appointment, $this->token) ? $appointment : null;
    }

    /** Se mueve con el mismo doctor. */
    #[Computed]
    public function doctors(): Collection
    {
        return Doctor::query()->whereKey($this->appointment?->doctor_id)->get();
    }

    protected function movingAppointment(): ?Appointment
    {
        return $this->appointment;
    }

    #[Computed]
    public function mode(): RescheduleMode
    {
        return app(PatientChangePolicy::class)->rescheduleMode($this->appointment);
    }

    #[Computed]
    public function canChange(): bool
    {
        return app(PatientChangePolicy::class)->canChange($this->appointment);
    }

    #[Computed]
    public function pendingRequest(): ?RescheduleRequest
    {
        return $this->appointment->rescheduleRequests()->where('status', RescheduleRequestStatus::Pending)->with('options')->latest('id')->first();
    }

    public function maxOptions(): int
    {
        return (int) Setting::get('booking.max_reschedule_options', 3);
    }

    /** Nivel 1: un horario. Nivel 2: hasta 3, se marcan y desmarcan. */
    public function pick(string $time): void
    {
        $this->error = null;

        if (! $this->daySlots->contains('time', $time)) {
            return;
        }

        $key = $this->date.' '.$time;

        if ($this->mode === RescheduleMode::Automatic) {
            $this->chosen = [$key];

            return;
        }

        if (in_array($key, $this->chosen, true)) {
            $this->chosen = array_values(array_diff($this->chosen, [$key]));
        } elseif (count($this->chosen) < $this->maxOptions()) {
            $this->chosen[] = $key;
            sort($this->chosen);
        } else {
            $this->error = 'Puedes proponer hasta '.$this->maxOptions().' horarios. Quita uno para elegir otro.';
        }
    }

    public function remove(string $key): void
    {
        $this->chosen = array_values(array_diff($this->chosen, [$key]));
    }

    public function save(AppointmentService $appointments, RescheduleRequestService $requests)
    {
        $appointment = $this->appointment;
        abort_if($appointment === null, 403);

        if ($this->chosen === [] || ! $this->canChange) {
            return null;
        }

        $this->validate(['reason' => ['nullable', 'string', 'max:250']]);
        $starts = array_map(fn (string $key) => CarbonImmutable::parse($key), $this->chosen);

        try {
            if ($this->mode === RescheduleMode::Automatic) {
                $appointments->rescheduleByPatient($appointment, $starts[0]);
                $message = '¡Listo! Tu cita se cambió al '.mb_strtolower(Dates::day($starts[0])).', '.Dates::time($starts[0]->format('H:i'));
            } else {
                $requests->requestByPatient($appointment, $starts, filled($this->reason) ? $this->reason : null);
                $message = 'Enviamos tu pedido. Te avisaremos por WhatsApp cuando el consultorio elija uno de tus horarios. Mientras tanto, tu cita sigue como estaba.';
            }
        } catch (BookingException $e) {
            $this->chosen = [];
            $this->refreshDays();
            $this->error = $e->getMessage();

            return null;
        }

        if ($this->token && ($link = app(MagicLinkService::class)->find($this->token))) {
            app(PatientAccess::class)->actWithLink(request(), $link);
        }

        session()->flash('status', $message);

        return $this->redirect($this->backUrl());
    }

    public function backUrl(): string
    {
        return $this->token ? route('patient.link', $this->token) : route('site.account');
    }

    public function render(): View
    {
        return view('livewire.site.change-appointment');
    }
}
