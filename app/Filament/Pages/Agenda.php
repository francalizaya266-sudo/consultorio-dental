<?php

namespace App\Filament\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Actions\CreateAppointmentAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\ClinicalAlerts;
use App\Filament\Support\Notify;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\ScheduleBlock;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use App\Services\Billing\BillingService;
use App\Services\Booking\AppointmentService;
use App\Support\Money;
use App\Support\Phone;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;

/**
 * Agenda en calendario: por día (una columna por doctor) o por semana (un doctor).
 * Las citas se mueven arrastrándolas. Al tocar una cita se abre un panel al costado con sus datos y
 * botones; al tocar un espacio libre, "Nueva cita" en una ventana sobre la agenda.
 */
class Agenda extends Page
{
    /** Píxeles por minuto en la grilla. */
    public const SCALE = 1.4;

    protected string $view = 'filament.pages.agenda';

    protected static ?string $title = 'Agenda';

    protected static ?string $slug = 'agenda';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    #[Url]
    public string $date = '';

    #[Url]
    public string $mode = 'day';

    #[Url]
    public ?int $doctor = null;

    /** La vista semanal se eligió sola (no la pidió el usuario): en un celular se cambia a la del día. */
    protected bool $weekByDefault = false;

    /** @var array<int, Appointment|null> Citas abiertas en el panel durante esta petición. */
    protected array $panelAppointments = [];

    public function mount(): void
    {
        $this->date = $this->safeDate($this->date)->toDateString();
        $this->mode = in_array($this->mode, ['day', 'week'], true) ? $this->mode : 'day';
        $this->doctor = $this->managesAll() ? ($this->doctor ?? $this->doctors()->first()?->getKey()) : $this->ownDoctorId();

        // El doctor ve primero su semana.
        if (! $this->managesAll() && ! request()->has('mode')) {
            $this->mode = 'week';
            $this->weekByDefault = true;
        }
    }

    public function shift(int $steps): void
    {
        $this->date = $this->day()->addDays($steps * ($this->mode === 'week' ? 7 : 1))->toDateString();
    }

    public function goToday(): void
    {
        $this->date = today()->toDateString();
    }

    public function updatedDate(): void
    {
        $this->date = $this->safeDate($this->date)->toDateString();
    }

    public function updatedDoctor(): void
    {
        if (! $this->managesAll()) {
            $this->doctor = $this->ownDoctorId();
        }
    }

    /** Se abre al soltar una cita en otro horario: confirma antes de mover y avisar al paciente. */
    public function moveAction(): Action
    {
        return Action::make('move')
            ->requiresConfirmation()
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading('¿Mover la cita?')
            ->modalDescription(function (array $arguments) {
                $appointment = Appointment::with(['patient', 'doctor'])->find($arguments['id'] ?? null);
                [$doctor, $start] = $this->target($arguments);

                if (! $appointment || ! $doctor || ! $start) {
                    return null;
                }

                $where = $doctor->is($appointment->doctor) ? '' : ' con '.$doctor->name;

                return "{$appointment->patient->full_name} pasa al ".$start->translatedFormat('l d \d\e F \a \l\a\s H:i').$where
                    .'. Se preparará el aviso para que el paciente confirme.';
            })
            ->modalSubmitActionLabel('Sí, mover')
            ->action(function (array $arguments) {
                $appointment = Appointment::find($arguments['id'] ?? null);
                [$doctor, $start] = $this->target($arguments);

                if (! $appointment || ! $doctor || ! $start || ! auth()->user()->can('update', $appointment)) {
                    return;
                }

                Notify::run(
                    fn () => app(AppointmentService::class)->rescheduleByStaff($appointment, $start, auth()->user(), $doctor),
                    'Cita movida. El aviso al paciente está listo en "Avisos por enviar".',
                );
            });
    }

    /** Panel al costado con la cita: datos, saldo y los botones del día, sin salir de la agenda. */
    public function appointmentAction(): Action
    {
        return Action::make('appointment')
            ->record(fn (array $arguments) => $this->panelAppointment($arguments['id'] ?? null))
            ->authorize(fn (?Appointment $record) => $record !== null && auth()->user()->can('view', $record))
            ->slideOver()
            ->modalWidth(Width::Medium)
            ->modalHeading(fn (Appointment $record) => $record->patient->full_name)
            ->modalDescription(fn (Appointment $record) => ucfirst($record->starts_at->translatedFormat('l j \d\e F'))
                .' · '.$record->starts_at->format('H:i').'–'.$record->ends_at->format('H:i'))
            ->modalContent(fn (Appointment $record) => view('filament.pages.agenda-appointment', $this->panelData($record)))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Cerrar')
            ->extraModalFooterActions(fn () => AppointmentActions::panel());
    }

    /** Se abre al tocar un espacio libre: ya viene con el doctor, el día y la hora. */
    public function createAppointmentAction(): Action
    {
        return CreateAppointmentAction::make();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $columns = $this->columns();
        [$from, $to] = $this->visibleMinutes($columns);

        return [
            'columns' => $columns,
            'from' => $from,
            'to' => $to,
            'scale' => self::SCALE,
            'hours' => range(intdiv($from, 60), intdiv($to - 1, 60)),
            'doctors' => $this->managesAll() ? $this->doctors()->pluck('name', 'id') : collect(),
            'heading' => $this->mode === 'week'
                ? 'Semana del '.$this->weekStart()->translatedFormat('j \d\e F')
                : ucfirst($this->day()->translatedFormat('l j \d\e F')),
            'weekByDefault' => $this->weekByDefault,
        ];
    }

    /**
     * Columnas de la grilla con su horario de atención, bloqueos y citas.
     *
     * @return list<array<string, mixed>>
     */
    private function columns(): array
    {
        $targets = $this->mode === 'week'
            ? collect(range(0, 6))
                ->map(fn (int $i) => [$this->doctors()->firstWhere('id', $this->doctor), $this->weekStart()->addDays($i)])
                ->filter(fn (array $t) => $t[0] !== null)
            : $this->doctors()->map(fn (Doctor $d) => [$d, $this->day()]);

        return $targets->map(function (array $target) {
            /** @var Doctor $doctor */
            [$doctor, $day] = $target;

            return [
                'key' => $doctor->getKey().'|'.$day->toDateString(),
                'title' => $this->mode === 'week' ? ucfirst($day->translatedFormat('D j')) : $doctor->name,
                'subtitle' => $this->mode === 'week' ? null : $doctor->specialty,
                'isToday' => $day->isToday(),
                'color' => $doctor->agenda_color ?: '#1e4f91',
                'date' => $day->toDateString(),
                'doctorId' => $doctor->getKey(),
                'treatments' => $doctor->treatments->modelKeys(),
                'working' => app(AvailabilityService::class)->workingRanges($doctor, $day)
                    ->map(fn (array $r) => ['start' => $this->minuteOf($r['start']), 'end' => $this->minuteOf($r['end'])])
                    ->all(),
                'blocks' => ScheduleBlock::query()
                    ->affecting($doctor, $day->startOfDay(), $day->endOfDay())
                    ->get()
                    ->map(fn (ScheduleBlock $b) => [
                        'start' => $b->starts_at->lt($day) ? 0 : $this->minuteOf($b->starts_at),
                        'end' => $b->ends_at->gt($day->endOfDay()) ? 24 * 60 : $this->minuteOf($b->ends_at),
                        'label' => $b->reason ?: $b->type->getLabel(),
                    ])
                    ->all(),
                'appointments' => $this->appointmentsFor($doctor, $day)->all(),
            ];
        })
            ->reject(fn (array $column) => $this->mode === 'week' && $column['working'] === [] && $column['appointments'] === [])
            ->values()
            ->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function appointmentsFor(Doctor $doctor, CarbonImmutable $day): Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $seesMoney = $user->can('viewAny', Charge::class);

        return Appointment::query()
            ->with(['patient' => fn ($patient) => $patient->with('account')->when($seesMoney, fn ($q) => $q->withBalance()), 'treatment'])
            ->whereBelongsTo($doctor)
            ->whereBetween('starts_at', [$day->startOfDay(), $day->endOfDay()])
            ->where('status', '!=', AppointmentStatus::Cancelled)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (Appointment $a) => [
                'id' => $a->getKey(),
                'start' => $this->minuteOf($a->starts_at),
                'end' => $this->minuteOf($a->ends_at),
                'until' => $this->minuteOf($a->occupied_until),
                'time' => $a->starts_at->format('H:i').'–'.$a->ends_at->format('H:i'),
                'patient' => $a->patient->full_name,
                'phone' => $a->patient->account ? Phone::display($a->patient->account->phone) : null,
                'treatment' => $a->treatment->name,
                'treatmentId' => $a->treatment_id,
                'status' => $a->status->value,
                'statusLabel' => $a->status->getLabel(),
                'needsCall' => $a->needsCall(),
                'isNew' => $a->is_new_patient,
                'owes' => $seesMoney && (float) $a->patient->balance_due > 0 ? Money::short($a->patient->balance_due) : null,
                'hasNote' => filled($a->patient_note) || filled($a->internal_notes),
                'url' => AppointmentResource::getUrl('view', ['record' => $a]),
                'draggable' => $a->status->isChangeable() && $user->can('update', $a),
            ]);
    }

    /** La cita del panel, cargada una sola vez por petición. */
    private function panelAppointment(mixed $id): ?Appointment
    {
        $id = (int) $id;

        if (! array_key_exists($id, $this->panelAppointments)) {
            $this->panelAppointments[$id] = Appointment::query()
                ->with(['patient.account', 'patient.medicalBackground', 'treatment', 'doctor'])
                ->find($id);
        }

        return $this->panelAppointments[$id];
    }

    /**
     * Lo que muestra el panel. El saldo y lo que hay que cobrar solo para quien maneja la caja;
     * las alertas médicas solo para quien ve lo clínico.
     *
     * @return array<string, mixed>
     */
    private function panelData(Appointment $appointment): array
    {
        /** @var User $user */
        $user = auth()->user();
        $seesMoney = $user->can('viewAny', Charge::class);
        $balance = $seesMoney ? $appointment->patient->balance() : null;
        $toCharge = $seesMoney && in_array($appointment->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed, AppointmentStatus::CheckedIn, AppointmentStatus::InProgress], true)
            ? app(BillingService::class)->estimateFor($appointment)
            : null;

        return [
            'appointment' => $appointment,
            'phone' => $appointment->patient->account ? Phone::display($appointment->patient->account->phone) : null,
            'phoneLink' => $appointment->patient->account ? 'tel:'.Phone::normalize($appointment->patient->account->phone) : null,
            'balance' => $balance,
            'toCharge' => $toCharge,
            'alerts' => $user->canSeeClinical() ? ClinicalAlerts::for($appointment->patient) : null,
            'patientUrl' => PatientResource::getUrl('view', ['record' => $appointment->patient]),
            'appointmentUrl' => AppointmentResource::getUrl('view', ['record' => $appointment]),
        ];
    }

    /** @return Collection<int, Doctor> */
    private function doctors(): Collection
    {
        return once(fn () => Doctor::query()
            ->active()
            ->with('treatments')
            ->when(! $this->managesAll(), fn (Builder $q) => $q->whereKey($this->ownDoctorId() ?? 0))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get());
    }

    /**
     * Rango de minutos a mostrar, en horas enteras: de la primera hora de atención (o cita) a la última.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return array{0: int, 1: int}
     */
    private function visibleMinutes(array $columns): array
    {
        $edges = collect($columns)->flatMap(fn (array $c) => [
            ...array_map(fn (array $r) => [$r['start'], $r['end']], $c['working']),
            ...array_map(fn (array $a) => [$a['start'], $a['until']], $c['appointments']),
        ]);

        $from = (int) ($edges->min(fn (array $e) => $e[0]) ?? 8 * 60);
        $to = (int) ($edges->max(fn (array $e) => $e[1]) ?? 20 * 60);

        return [intdiv($from, 60) * 60, (int) ceil($to / 60) * 60];
    }

    /**
     * Doctor y hora donde se soltó la cita.
     *
     * @param  array<string, mixed>  $arguments
     * @return array{0: Doctor|null, 1: CarbonImmutable|null}
     */
    private function target(array $arguments): array
    {
        [$doctorId, $date] = array_pad(explode('|', (string) ($arguments['column'] ?? '')), 2, null);
        $time = (string) ($arguments['time'] ?? '');

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date) || ! preg_match('/^\d{2}:\d{2}$/', $time)) {
            return [null, null];
        }

        return [
            $this->doctors()->firstWhere('id', (int) $doctorId),
            CarbonImmutable::parse("{$date} {$time}"),
        ];
    }

    private function minuteOf(\DateTimeInterface $time): int
    {
        return (int) $time->format('G') * 60 + (int) $time->format('i');
    }

    private function day(): CarbonImmutable
    {
        return $this->safeDate($this->date);
    }

    private function weekStart(): CarbonImmutable
    {
        return $this->day()->startOfWeek(CarbonImmutable::MONDAY);
    }

    private function safeDate(?string $date): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)
            ? CarbonImmutable::parse($date)->startOfDay()
            : CarbonImmutable::today();
    }

    private function managesAll(): bool
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->managesAllAgendas();
    }

    private function ownDoctorId(): ?int
    {
        /** @var User $user */
        $user = auth()->user();

        return $user->doctorId();
    }
}
