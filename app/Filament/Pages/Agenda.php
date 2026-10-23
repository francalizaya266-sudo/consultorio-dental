<?php

namespace App\Filament\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Support\Notify;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\ScheduleBlock;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use App\Services\Booking\AppointmentService;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use UnitEnum;

/**
 * Agenda en calendario: por día (una columna por doctor) o por semana (un doctor).
 * Las citas se mueven arrastrándolas; un clic en un espacio libre abre "Nueva cita".
 */
class Agenda extends Page
{
    /** Píxeles por minuto en la grilla. */
    public const SCALE = 1.4;

    protected string $view = 'filament.pages.agenda';

    protected static ?string $title = 'Agenda';

    protected static ?string $slug = 'agenda';

    protected static string|UnitEnum|null $navigationGroup = 'Agenda';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    #[Url]
    public string $date = '';

    #[Url]
    public string $mode = 'day';

    #[Url]
    public ?int $doctor = null;

    /** La vista semanal se eligió sola (no la pidió el usuario): en un celular se cambia a la del día. */
    protected bool $weekByDefault = false;

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
            'createUrl' => AppointmentResource::getUrl('create'),
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

        return Appointment::query()
            ->with(['patient', 'treatment'])
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
                'treatment' => $a->treatment->name,
                'treatmentId' => $a->treatment_id,
                'status' => $a->status->value,
                'statusLabel' => $a->status->getLabel(),
                'needsCall' => $a->needsCall(),
                'isNew' => $a->is_new_patient,
                'url' => AppointmentResource::getUrl('view', ['record' => $a]),
                'draggable' => $a->status->isChangeable() && $user->can('update', $a),
            ]);
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
