<?php

namespace App\Filament\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Actions\CheckoutAction;
use App\Filament\Actions\CreateAppointmentAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryActions;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryResource;
use App\Filament\Support\ClinicalAlerts;
use App\Filament\Support\Notify;
use App\Models\Appointment;
use App\Models\Charge;
use App\Models\Doctor;
use App\Models\ScheduleBlock;
use App\Models\Treatment;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\GapFinder;
use App\Services\Billing\BillingService;
use App\Services\Booking\AppointmentService;
use App\Support\Dates;
use App\Support\Money;
use App\Support\Phone;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Pages\Page;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * Agenda en calendario: por día (una columna por doctor), por semana (un doctor) o el día en lista (el celular).
 * Las citas se mueven arrastrándolas. Al tocar una cita se abre un panel al costado con sus datos y
 * botones; al tocar un espacio libre, "Nueva cita" en una ventana sobre la agenda. En la computadora,
 * al lado va el mini calendario con lo lleno que está cada día, "Buscar hueco" y la lista de espera que cabe.
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

    /** Tratamiento elegido en "Buscar hueco". */
    public ?int $findTreatment = null;

    /** Mes del mini calendario (Y-m) cuando se mira otro que el del día; vacío, el del día. */
    public string $month = '';

    /** La vista se eligió sola (no la pidió el usuario): en un celular se cambia a la lista del día. */
    protected bool $modeByDefault = false;

    /**
     * Lo que toca hacer con la cita según su estado, en orden: en la lista va el primer botón que la persona puede usar
     * (la recepción y el doctor ven pasos distintos, y en una cita de un día anterior solo se puede cerrar).
     */
    private const NEXT_STEPS = [
        'pending' => ['listConfirm', 'listMissed', 'listArrived', 'listStart', 'listComplete'],
        'confirmed' => ['listMissed', 'listArrived', 'listStart', 'listComplete'],
        'checked_in' => ['listStart', 'listComplete'],
        'in_progress' => ['listContinue', 'listStart', 'listComplete'],
        'completed' => ['listCheckout'],
    ];

    /** @var array<int, Appointment|null> Citas abiertas en el panel durante esta petición. */
    protected array $panelAppointments = [];

    public function mount(): void
    {
        $this->date = $this->safeDate($this->date)->toDateString();
        $this->mode = in_array($this->mode, ['day', 'week', 'list'], true) ? $this->mode : 'day';
        $this->doctor = $this->managesAll() ? ($this->doctor ?? $this->doctors()->first()?->getKey()) : $this->ownDoctorId();

        if (! request()->has('mode')) {
            $this->modeByDefault = true;

            // El doctor ve primero su semana.
            if (! $this->managesAll()) {
                $this->mode = 'week';
            }
        }
    }

    public function shift(int $steps): void
    {
        $this->date = $this->day()->addDays($steps * ($this->mode === 'week' ? 7 : 1))->toDateString();
        $this->month = '';
    }

    public function goToday(): void
    {
        $this->date = today()->toDateString();
        $this->month = '';
    }

    public function updatedDate(): void
    {
        $this->date = $this->safeDate($this->date)->toDateString();
        $this->month = '';
    }

    /** Mini calendario: otro mes, sin cambiar el día que se mira. */
    public function shiftMonth(int $steps): void
    {
        $this->month = $this->calendarMonth()->addMonthsNoOverflow($steps)->format('Y-m');
    }

    /** "Buscar hueco": elige el tratamiento (de la lista o con un toque; otro toque en el mismo lo quita). */
    public function findFor(int|string|null $treatment, bool $toggle = true): void
    {
        $treatment = is_numeric($treatment) ? (int) $treatment : null;
        $this->findTreatment = $toggle && $treatment === $this->findTreatment ? null : $treatment;
    }

    public function updatedDoctor(): void
    {
        if (! $this->managesAll()) {
            $this->doctor = $this->ownDoctorId();
        }
    }

    /** El título es el día (o la semana) que se ve: así no hace falta una línea aparte para la fecha. */
    public function getHeading(): string
    {
        return $this->mode === 'week'
            ? 'Semana del '.$this->weekStart()->translatedFormat('j \d\e F')
            : ucfirst($this->day()->translatedFormat('l j \d\e F'));
    }

    /** @return list<string> */
    public function getPageClasses(): array
    {
        return ['agenda-page'];
    }

    /** "Nueva cita" a la vista, sin buscar un espacio libre: llega con el día que se mira (y el doctor en la semana). */
    protected function getHeaderActions(): array
    {
        return [
            CreateAppointmentAction::make('newAppointment')
                ->arguments(['date' => $this->date, 'doctor' => $this->mode === 'week' ? $this->doctor : null])
                ->visible(fn () => AppointmentResource::canCreate()),
        ];
    }

    /**
     * Se abre al soltar una cita en otro horario: dice de dónde a dónde pasa, avisa si cae fuera del
     * horario de atención y pregunta si ya se acordó con el paciente (entonces no se le pide confirmar).
     */
    public function moveAction(): Action
    {
        return Action::make('move')
            ->requiresConfirmation()
            ->icon(Heroicon::OutlinedArrowsRightLeft)
            ->modalHeading(fn (array $arguments) => $this->outsideHours($arguments) ? '¿Mover la cita fuera del horario?' : '¿Mover la cita?')
            ->modalIconColor(fn (array $arguments) => $this->outsideHours($arguments) ? 'warning' : 'primary')
            ->modalDescription(function (array $arguments) {
                $appointment = Appointment::with(['patient', 'doctor'])->find($arguments['id'] ?? null);
                [$doctor, $start] = $this->target($arguments);

                if (! $appointment || ! $doctor || ! $start) {
                    return null;
                }

                $where = $doctor->is($appointment->doctor) ? '' : ' con '.$doctor->name;
                $text = "{$appointment->patient->full_name} pasa del ".Dates::dayAndTime($appointment->starts_at)
                    .' al '.Dates::dayAndTime($start).$where.'.';

                if ($hours = $this->outsideHours($arguments)) {
                    $text .= " Ese horario está fuera de la atención de {$doctor->name} ({$hours}).";
                }

                return $text;
            })
            ->schema([
                Checkbox::make('agreed')
                    ->label('Ya lo acordé con el paciente')
                    ->helperText('Queda confirmada y no se le pide confirmar. Si no, se prepara el aviso para que confirme.'),
            ])
            ->modalSubmitActionLabel(fn (array $arguments) => $this->outsideHours($arguments) ? 'Mover igual' : 'Sí, mover')
            ->action(function (array $arguments, array $data) {
                $appointment = Appointment::find($arguments['id'] ?? null);
                [$doctor, $start] = $this->target($arguments);

                if (! $appointment || ! $doctor || ! $start || ! auth()->user()->can('update', $appointment)) {
                    return;
                }

                $agreed = (bool) ($data['agreed'] ?? false);

                Notify::run(
                    fn () => app(AppointmentService::class)->rescheduleByStaff($appointment, $start, auth()->user(), $doctor, $agreed),
                    $agreed ? 'Cita movida y confirmada.' : 'Cita movida. El aviso al paciente está listo en Avisos.',
                );
            });
    }

    /**
     * Si la cita, soltada ahí, queda fuera del horario de atención del doctor: devuelve ese horario
     * ("de 09:00 a 13:00 y de 15:00 a 19:00", o "no atiende ese día"); null si cae dentro.
     */
    private function outsideHours(array $arguments): ?string
    {
        $appointment = Appointment::with('treatment')->find($arguments['id'] ?? null);
        [$doctor, $start] = $this->target($arguments);

        if (! $appointment || ! $doctor || ! $start) {
            return null;
        }

        $end = $start->addMinutes((int) $appointment->starts_at->diffInMinutes($appointment->ends_at));
        $ranges = app(AvailabilityService::class)->workingRanges($doctor, $start);

        if ($ranges->contains(fn (array $r) => $start->gte($r['start']) && $end->lte($r['end']))) {
            return null;
        }

        return $ranges->isEmpty()
            ? 'no atiende ese día'
            : $ranges->map(fn (array $r) => 'de '.$r['start']->format('H:i').' a '.$r['end']->format('H:i'))->join(', ', ' y ');
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
            // Se cierra con la X o tocando fuera: un botón "Cerrar" más empujaba los del día a otra línea en el celular.
            ->modalCancelAction(false)
            ->extraModalFooterActions(fn () => AppointmentActions::panel());
    }

    /** Se abre al tocar un espacio libre o una hora de "Buscar hueco": ya viene con el doctor, el día, la hora (y el tratamiento). */
    public function createAppointmentAction(): Action
    {
        return CreateAppointmentAction::make();
    }

    /** Ofrecer por WhatsApp la hora libre a alguien de la lista de espera, desde el panel de la agenda. */
    public function offerAction(): Action
    {
        return WaitlistEntryActions::offer()
            ->record(fn (array $arguments) => WaitlistEntry::query()->with(['patient.account', 'treatment'])->whereHas('patient')->find($arguments['entry'] ?? null))
            ->hidden(fn (?WaitlistEntry $record) => $record === null);
    }

    /*
     * El botón del siguiente paso en la lista del día: las mismas acciones del panel de la cita, con otro nombre
     * para no confundirse con las del panel.
     */

    public function listConfirmAction(): Action
    {
        return $this->forListedAppointment(AppointmentActions::confirm(), 'listConfirm');
    }

    public function listMissedAction(): Action
    {
        return $this->forListedAppointment(AppointmentActions::missed(), 'listMissed');
    }

    public function listArrivedAction(): Action
    {
        return $this->forListedAppointment(AppointmentActions::arrived(), 'listArrived');
    }

    public function listStartAction(): Action
    {
        return $this->forListedAppointment(AppointmentActions::start(), 'listStart');
    }

    public function listContinueAction(): Action
    {
        return $this->forListedAppointment(AppointmentActions::continueConsultation(), 'listContinue');
    }

    public function listCompleteAction(): Action
    {
        return $this->forListedAppointment(AppointmentActions::complete(), 'listComplete');
    }

    public function listCheckoutAction(): Action
    {
        return $this->forListedAppointment(CheckoutAction::make(), 'listCheckout');
    }

    /** El primer paso que esta persona puede dar con la cita (o ninguno, si ya no queda nada por hacer). */
    public function nextStep(int $id, string $status): ?Action
    {
        foreach (self::NEXT_STEPS[$status] ?? [] as $name) {
            $action = ($this->getAction($name))(['id' => $id]);

            if ($action->isVisible()) {
                return $action;
            }
        }

        return null;
    }

    private function forListedAppointment(Action $action, string $name): Action
    {
        return $action
            ->name($name)
            ->record(fn (array $arguments) => $this->panelAppointment($arguments['id'] ?? null))
            ->hidden(fn (?Appointment $record) => $record === null || ! auth()->user()->can('view', $record));
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
            'modeByDefault' => $this->modeByDefault,
            'rail' => $this->rail(),
            'list' => $this->mode === 'list' ? $this->listOfTheDay($columns) : null,
        ];
    }

    /**
     * El panel al costado: el mes con lo lleno de cada día, "Buscar hueco" y quién de la lista de espera cabe.
     * En la semana, solo del doctor que se mira.
     *
     * @return array<string, mixed>
     */
    private function rail(): array
    {
        $finder = app(GapFinder::class);
        $doctors = $this->railDoctors();
        $month = $this->calendarMonth();
        $first = $month->startOfWeek(CarbonImmutable::MONDAY);
        $last = $month->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);
        $load = $finder->occupancy($doctors, $first, $last);
        $selected = $this->mode === 'week'
            ? [$this->weekStart()->toDateString(), $this->weekStart()->addDays(6)->toDateString()]
            : [$this->date, $this->date];

        $days = [];

        for ($day = $first; $day->lte($last); $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = [
                'date' => $date,
                'number' => $day->day,
                'label' => ucfirst($day->translatedFormat('l j \d\e F')).self::loadLabel($load[$date] ?? null),
                'load' => $load[$date] ?? null,
                'level' => self::loadLevel($load[$date] ?? null),
                'isOtherMonth' => $day->month !== $month->month,
                'isToday' => $day->isToday(),
                'isPast' => $day->lt(today()),
                'isSelected' => $date >= $selected[0] && $date <= $selected[1],
                'isRangeStart' => $date === $selected[0],
                'isRangeEnd' => $date === $selected[1],
            ];
        }

        [$quick, $others] = $this->findableTreatments($doctors);
        $treatment = $this->findTreatment ? $quick->concat($others)->firstWhere('id', $this->findTreatment) : null;

        /** @var User $user */
        $user = auth()->user();

        return [
            'month' => ucfirst($month->translatedFormat('F Y')),
            'days' => $days,
            'quick' => $quick,
            'others' => $others,
            'treatment' => $treatment,
            'slots' => $treatment ? array_map(fn (array $day) => [
                'label' => $day['date']->isToday() ? 'Hoy' : ($day['date']->isTomorrow() ? 'Mañana' : ucfirst($day['date']->translatedFormat('l j'))),
                'date' => $day['date']->toDateString(),
                'slots' => array_map(fn (array $slot) => [
                    'time' => $slot['time'],
                    'recommended' => $slot['recommended'],
                    'doctor' => $slot['doctor']->getKey(),
                    'doctorName' => $slot['doctor']->name,
                    'initials' => self::initials($slot['doctor']->name),
                    'color' => $slot['doctor']->agenda_color ?: '#1e4f91',
                ], $day['slots']),
            ], $finder->nextSlots($treatment, $doctors, $this->day())) : null,
            'severalDoctors' => $doctors->count() > 1,
            'waitlist' => $user->can('viewAny', WaitlistEntry::class) ? $finder->waitlistFits($doctors, $this->day()) : [],
            'waitlistUrl' => $user->can('viewAny', WaitlistEntry::class) ? WaitlistEntryResource::getUrl() : null,
            'dayName' => $this->day()->isToday() ? 'hoy' : ($this->day()->isTomorrow() ? 'mañana' : 'el '.$this->day()->translatedFormat('l j')),
            'searchDays' => GapFinder::SEARCH_DAYS,
            'canCreate' => AppointmentResource::canCreate(),
        ];
    }

    /** @return Collection<int, Doctor> */
    private function railDoctors(): Collection
    {
        return $this->mode === 'week' ? $this->doctors()->where('id', $this->doctor)->values() : $this->doctors();
    }

    /**
     * Para "Buscar hueco": los cuatro tratamientos que más se agendan (a la vista, un toque) y los demás en una lista.
     * Solo los que hace alguno de los doctores que se miran.
     *
     * @param  Collection<int, Doctor>  $doctors
     * @return array{0: Collection<int, Treatment>, 1: Collection<int, Treatment>}
     */
    private function findableTreatments(Collection $doctors): array
    {
        $offered = Treatment::query()
            ->active()
            ->whereHas('doctors', fn (Builder $query) => $query->whereKey($doctors->modelKeys()))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        $popular = Appointment::query()
            ->whereIn('doctor_id', $doctors->modelKeys())
            ->where('starts_at', '>=', now()->subDays(90))
            ->select('treatment_id', DB::raw('count(*) as total'))
            ->groupBy('treatment_id')
            ->orderByDesc('total')
            ->pluck('treatment_id');

        $quick = $offered
            ->sortBy(fn (Treatment $treatment) => [$popular->search($treatment->getKey()) === false ? PHP_INT_MAX : $popular->search($treatment->getKey()), $treatment->sort_order])
            ->take(4)
            ->values();

        return [$quick, $offered->reject(fn (Treatment $treatment) => $quick->contains($treatment))->values()];
    }

    /**
     * El día en lista, para el celular: las citas de todos los doctores en orden de hora, los bloqueos, los huecos
     * donde aún entra una cita (con "Agendar") y la línea de ahora.
     *
     * @param  list<array<string, mixed>>  $columns
     * @return array<string, mixed>
     */
    private function listOfTheDay(array $columns): array
    {
        $day = $this->day();
        $several = count($columns) > 1;
        $nowMinute = now()->hour * 60 + now()->minute;
        $items = [];
        $free = 0;

        $this->preloadPanel(collect($columns)->flatMap(fn (array $column) => array_column($column['appointments'], 'id'))->all());

        foreach ($columns as $column) {
            $doctor = $several ? ['name' => $column['title'], 'initials' => $column['initials'], 'color' => $column['color']] : null;

            foreach ($column['appointments'] as $appointment) {
                $items[] = ['type' => 'appointment', 'at' => $appointment['start'], 'order' => 1, 'doctor' => $doctor, 'length' => self::duration($appointment['end'] - $appointment['start'])] + $appointment;
            }

            foreach ($column['blocks'] as $block) {
                $wholeDay = $block['start'] === 0 && $block['end'] >= 24 * 60;
                $items[] = [
                    'type' => 'block',
                    'at' => $wholeDay ? -1 : $block['start'],
                    'order' => 0,
                    'doctor' => $doctor,
                    'time' => $wholeDay ? 'Todo el día' : self::clock($block['start']).' – '.self::clock(min($block['end'], 24 * 60 - 1)),
                    'label' => $block['label'],
                ];
            }

            if ($day->gte(today())) {
                $doctorModel = $this->doctors()->firstWhere('id', $column['doctorId']);

                foreach (app(AvailabilityService::class)->dayGaps($doctorModel, $day, $day->isToday() ? now() : null)['gaps'] as $gap) {
                    $minutes = (int) $gap['start']->diffInMinutes($gap['end']);
                    $free += $minutes;
                    $items[] = [
                        'type' => 'gap',
                        'at' => $this->minuteOf($gap['start']),
                        'order' => 2,
                        'doctor' => $doctor,
                        'doctorId' => $column['doctorId'],
                        'start' => $gap['start']->format('H:i'),
                        'time' => $gap['start']->format('H:i').' – '.$gap['end']->format('H:i'),
                        'length' => self::duration($minutes),
                    ];
                }
            }
        }

        if ($day->isToday()) {
            $items[] = ['type' => 'now', 'at' => $nowMinute, 'order' => -1, 'time' => now()->format('H:i')];
        }

        usort($items, fn (array $a, array $b) => [$a['at'], $a['order']] <=> [$b['at'], $b['order']]);

        $appointments = collect($items)->where('type', 'appointment');
        $waiting = $appointments->where('status', AppointmentStatus::CheckedIn->value)->count();

        return [
            'items' => $items,
            'summary' => collect([
                $appointments->isEmpty() ? 'Sin citas' : $appointments->count().' '.($appointments->count() === 1 ? 'cita' : 'citas'),
                $waiting ? $waiting.' en sala' : null,
                $free ? self::duration($free).' '.($free < 120 && $free >= 60 ? 'libre' : 'libres') : null,
            ])->filter()->implode(' · '),
            'week' => $this->weekStrip(),
            'canCreate' => AppointmentResource::canCreate(),
            'empty' => collect($columns)->contains(fn (array $column) => $column['working'] !== []) ? 'No hay citas este día.' : 'Nadie atiende este día.',
        ];
    }

    /**
     * Los días de la semana arriba de la lista, con lo lleno de cada uno. Los días que nadie atiende no van (salvo el que se mira).
     *
     * @return list<array<string, mixed>>
     */
    private function weekStrip(): array
    {
        $start = $this->weekStart();
        $load = app(GapFinder::class)->occupancy($this->doctors(), $start, $start->addDays(6));
        $letters = ['L', 'M', 'X', 'J', 'V', 'S', 'D'];

        return collect(range(0, 6))
            ->map(fn (int $i) => $start->addDays($i))
            ->filter(fn (CarbonImmutable $day) => ($load[$day->toDateString()] ?? null) !== null || $day->toDateString() === $this->date)
            ->map(fn (CarbonImmutable $day) => [
                'date' => $day->toDateString(),
                'letter' => $letters[$day->dayOfWeekIso - 1],
                'number' => $day->day,
                'label' => ucfirst($day->translatedFormat('l j')).self::loadLabel($load[$day->toDateString()] ?? null),
                'load' => $load[$day->toDateString()] ?? null,
                'level' => self::loadLevel($load[$day->toDateString()] ?? null),
                'isSelected' => $day->toDateString() === $this->date,
                'isToday' => $day->isToday(),
                'isPast' => $day->lt(today()),
            ])
            ->values()
            ->all();
    }

    /**
     * Las citas de la lista se cargan juntas (con lo que piden sus botones), no una por una.
     *
     * @param  list<int>  $ids
     */
    private function preloadPanel(array $ids): void
    {
        Appointment::query()
            ->with(['patient.account', 'patient.medicalBackground', 'treatment', 'doctor', 'consultation'])
            ->whereKey($ids)
            ->get()
            ->each(fn (Appointment $appointment) => $this->panelAppointments[$appointment->getKey()] = $appointment);
    }

    private function calendarMonth(): CarbonImmutable
    {
        return preg_match('/^\d{4}-\d{2}$/', $this->month)
            ? CarbonImmutable::createFromFormat('!Y-m', $this->month)->startOfMonth()
            : $this->day()->startOfMonth();
    }

    /** Lo lleno de un día en cuatro pasos, para el color de su barra. */
    private static function loadLevel(?int $load): string
    {
        return match (true) {
            $load === null => 'closed',
            $load >= 90 => 'full',
            $load >= 60 => 'busy',
            $load > 0 => 'some',
            default => 'empty',
        };
    }

    private static function loadLabel(?int $load): string
    {
        return match (true) {
            $load === null => ' · no se atiende',
            $load >= 90 => ' · lleno',
            default => ' · '.$load.' % ocupado',
        };
    }

    private static function duration(int $minutes): string
    {
        if ($minutes < 60) {
            return $minutes.' min';
        }

        return intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60) : '');
    }

    private static function clock(int $minute): string
    {
        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
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
                'weekday' => ucfirst($day->translatedFormat('D')),
                'dayNumber' => $day->day,
                'isToday' => $day->isToday(),
                'isPast' => $day->lt(today()),
                'color' => $doctor->agenda_color ?: '#1e4f91',
                'initials' => self::initials($doctor->name),
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

    /** Iniciales del doctor para su círculo en la cabecera: "Dr. Jassmanny Neil Vega Jaen" queda "JV" (el nombre y el primer apellido). */
    private static function initials(string $name): string
    {
        $words = collect(preg_split('/\s+/u', trim($name)) ?: [])
            ->reject(fn (string $word) => preg_match('/^(dr|dra|dr\(a\)|od|c\.?d)\.?$/iu', $word) === 1)
            ->values();

        $surname = $words->count() >= 4 ? $words->get(2) : $words->last();

        return mb_strtoupper(mb_substr((string) $words->first(), 0, 1).($words->count() > 1 ? mb_substr((string) $surname, 0, 1) : ''));
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
                'startTime' => $a->starts_at->format('H:i'),
                'patient' => $a->patient->full_name,
                'shortName' => $a->patient->short_name,
                'phone' => $a->patient->account ? Phone::display($a->patient->account->phone) : null,
                'phoneLink' => $a->patient->account ? 'tel:'.Phone::normalize($a->patient->account->phone) : null,
                'treatment' => $a->treatment->name,
                'treatmentId' => $a->treatment_id,
                'status' => $a->status->value,
                'statusLabel' => $a->status->getLabel(),
                'unclosed' => $a->isPastAndOpen(),
                'needsCall' => $a->needsCall() && ! $a->isPastAndOpen(),
                'isNew' => $a->is_new_patient,
                'owes' => $seesMoney && (float) $a->patient->balance_due > 0 ? Money::short($a->patient->balance_due) : null,
                'hasNote' => filled($a->patient_note) || filled($a->internal_notes),
                'url' => AppointmentResource::getUrl('view', ['record' => $a]),
                'draggable' => $a->status->isChangeable() && $user->can('update', $a),
            ])
            ->pipe(fn (Collection $cards) => self::inLanes($cards));
    }

    /**
     * Citas que coinciden en hora se reparten el ancho de la columna, lado a lado: ninguna tapa a otra.
     * Cada una lleva su carril ("lane") y cuántos carriles tiene su grupo ("lanes").
     *
     * @param  Collection<int, array<string, mixed>>  $cards  Ordenadas por hora de inicio.
     * @return Collection<int, array<string, mixed>>
     */
    public static function inLanes(Collection $cards): Collection
    {
        $placed = [];
        $group = [];
        $laneEnds = [];
        $groupEnd = -1;

        $close = function () use (&$placed, &$group, &$laneEnds) {
            foreach ($group as $card) {
                $placed[] = [...$card, 'lanes' => count($laneEnds)];
            }
            $group = [];
            $laneEnds = [];
        };

        foreach ($cards as $card) {
            if ($card['start'] >= $groupEnd) {
                $close();
            }

            $lane = collect($laneEnds)->search(fn (int $end) => $end <= $card['start']);
            $lane = $lane === false ? count($laneEnds) : $lane;
            $laneEnds[$lane] = $card['end'];
            $groupEnd = max($groupEnd, $card['end']);
            $group[] = [...$card, 'lane' => $lane];
        }

        $close();

        return collect($placed);
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
