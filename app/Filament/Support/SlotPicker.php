<?php

namespace App\Filament\Support;

use App\Enums\DayPeriod;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\LabOrder;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\User;
use App\Services\Agenda\AvailabilityService;
use App\Services\Agenda\GapFinder;
use App\Services\Agenda\Slot;
use App\Services\Patients\PatientInsights;
use App\Support\Dates;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

/**
 * Campos para elegir doctor, día y un horario libre según la agenda inteligente.
 * Recepción puede usar "otra hora" (por ejemplo, fuera del horario), pero el
 * sistema igual impide cruzar dos citas.
 */
final class SlotPicker
{
    /**
     * @param  Appointment|null  $moving  Cita que se mueve: su tratamiento es fijo y su horario no cuenta como ocupado.
     * @return list<Component|Field>
     */
    public static function fields(?Appointment $moving = null): array
    {
        $suggest = fn (Set $set, Get $get) => $set('slot', self::suggestedSlot($get, $moving));

        return [
            // Hora que se tocó en la agenda: se propone el primer horario libre desde ahí.
            Hidden::make('preferred_time')
                ->default(fn () => preg_match('/^\d{2}:\d{2}$/', (string) request('time')) ? request('time') : null)
                ->dehydrated(false),

            // El día que se tocó en la agenda: entonces no se cambia al día en que suele venir el paciente.
            Hidden::make('date_from_agenda')
                ->default(fn () => self::requestedDate()?->toDateString())
                ->dehydrated(false),

            // Primero qué se hace y después con quién: así lo cuenta el paciente al llamar.
            self::treatmentSelect($moving)->afterStateUpdated($suggest),
            self::doctorSelect($moving)->afterStateUpdated($suggest),

            DatePicker::make('date')
                ->label('Día')
                ->native(false)
                ->displayFormat('D d/m/Y')
                ->firstDayOfWeek(1)
                ->closeOnDateSelection()
                // Desde la ficha ("Agendar"), el próximo día en que suele venir el paciente.
                ->default(fn () => self::requestedDate() ?? ($moving ? null : self::nextUsualDay(Patient::find(request()->integer('patient')))) ?? today())
                ->minDate(today())
                ->required()
                ->live()
                // Si algo suyo sigue en el laboratorio y llega después de ese día, se avisa antes de agendar.
                ->helperText(fn (Get $get) => self::labWorkArrivingAfter($moving?->patient_id ?? $get('patient_id'), $get('date')))
                ->afterStateUpdated($suggest),

            // Todos los horarios a la vista, un toque cada uno (antes era una lista desplegable).
            ToggleButtons::make('slot')
                ->label('Horario libre')
                ->helperText(fn (Get $get) => self::slotsHint($get, $moving))
                // Día lleno: un toque lleva al siguiente con lugar (antes había que probar día por día).
                ->hintAction(Action::make('nextDayWithSlots')
                    ->label(fn (Get $get) => 'Ir al '.self::nextDayWithSlots($get, $moving)?->translatedFormat('l j'))
                    ->link()
                    ->visible(fn (Get $get) => self::slotOptions($get, $moving) === [] && self::nextDayWithSlots($get, $moving) !== null)
                    ->action(function (Get $get, Set $set) use ($moving): void {
                        $next = self::nextDayWithSlots($get, $moving);

                        if ($next) {
                            $set('date', $next->toDateString());
                            $set('slot', self::suggestedSlot($get, $moving));
                        }
                    }))
                ->options(fn (Get $get) => self::slotOptions($get, $moving))
                ->inline()
                ->extraAttributes(['class' => 'slot-buttons'])
                ->columnSpanFull()
                ->required(fn (Get $get) => ! $get('manual'))
                ->hidden(fn (Get $get) => (bool) $get('manual')),

            // Para una hora que la agenda no ofrece: fuera del horario de atención o, en una cita nueva, un
            // sobrecupo. Llega con la hora que se había elegido o tocado en la agenda.
            Toggle::make('manual')
                ->label($moving === null ? 'Otra hora (fuera del horario, o un sobrecupo)' : 'Otra hora (fuera del horario de atención)')
                ->live()
                ->afterStateUpdated(function (?bool $state, Get $get, Set $set): void {
                    if ($state && blank($get('manual_time'))) {
                        $set('manual_time', $get('slot') ?? $get('preferred_time'));
                    }
                }),

            TimePicker::make('manual_time')
                ->label('Hora')
                ->seconds(false)
                ->minutesStep(5)
                ->live()
                ->visible(fn (Get $get) => (bool) $get('manual'))
                ->required(fn (Get $get) => (bool) $get('manual')),

            // "Otra hora" deja elegir una que ya pasó (sirve para anotar a quien vino sin cita): antes se pregunta.
            Checkbox::make('past_time_ok')
                ->label('Esa hora ya pasó: registrar la cita igual')
                ->helperText('No se prepara aviso al paciente. Si fue un error, corrige la hora.')
                ->visible(fn (Get $get) => self::manualTimeIsPast($get))
                ->accepted(fn (Get $get) => self::manualTimeIsPast($get))
                ->validationMessages(['accepted' => 'Esa hora ya pasó. Corrígela o marca la casilla para registrar la cita igual.'])
                ->dehydrated(false),

            // Sobrecupo: la urgencia que el doctor aceptó ver aunque esa hora ya tenga cita. Solo con "Otra hora" en
            // una cita nueva, y se pregunta antes: sin marcarlo, dos citas nunca se cruzan.
            Checkbox::make('overbook')
                ->label(fn (Get $get) => 'Sobrecupo: agendar igual, encima de la cita de '.self::appointmentInTheWay($get)?->patient->full_name)
                ->helperText(fn (Get $get) => self::overbookWarning(self::appointmentInTheWay($get)))
                ->visible(fn (Get $get) => $moving === null && self::appointmentInTheWay($get) !== null)
                ->accepted(fn (Get $get) => self::appointmentInTheWay($get) !== null)
                ->validationMessages(['accepted' => 'Esa hora se cruza con otra cita. Elige otra hora, o marca "Sobrecupo" si el doctor aceptó verlo igual.']),
        ];
    }

    /** "Luis Quispe tiene esa hora (de 10:00 a 10:30). Márcalo solo si…" */
    private static function overbookWarning(?Appointment $inTheWay): ?string
    {
        return $inTheWay
            ? $inTheWay->patient->full_name.' ya tiene esa hora (de '.$inTheWay->starts_at->format('H:i').' a '.$inTheWay->ends_at->format('H:i')
                .'). Márcalo solo para una urgencia que el doctor aceptó ver: los dos quedan a la misma hora y esta cita se marca como sobrecupo.'
            : null;
    }

    /**
     * Con "Otra hora" en una cita nueva: la cita que ya ocupa la hora escrita. Null si esa hora está libre, si falta
     * algún dato o si lo que estorba es un bloqueo (encima de un bloqueo no hay sobrecupo).
     */
    private static function appointmentInTheWay(Get $get): ?Appointment
    {
        $doctor = Doctor::find($get('doctor_id'));
        $treatment = Treatment::find($get('treatment_id'));

        if (! $get('manual') || blank($get('date')) || blank($get('manual_time')) || ! $doctor || ! $treatment) {
            return null;
        }

        $startsAt = CarbonImmutable::parse($get('date'))->setTimeFromTimeString($get('manual_time'));
        $duration = self::chosenDuration($get) ?? $doctor->durationFor($treatment);
        $patientId = filled($get('patient_id')) ? (int) $get('patient_id') : null;

        // Lo piden el texto, la casilla y su validación en el mismo pedido: se busca una sola vez.
        $memo = 'slot-picker.in-the-way.'.implode('|', [$doctor->getKey(), $treatment->getKey(), $startsAt->getTimestamp(), $duration, $patientId]);

        if (! request()->attributes->has($memo)) {
            $clash = app(AvailabilityService::class)->forPatient($patientId)->clashAt($doctor, $startsAt, $duration, $treatment->bufferMinutes());

            request()->attributes->set($memo, $clash instanceof Appointment ? $clash : null);
        }

        return request()->attributes->get($memo);
    }

    /** Con "Otra hora", la fecha y hora escritas ya quedaron atrás. */
    private static function manualTimeIsPast(Get $get): bool
    {
        if (! $get('manual') || blank($get('date')) || blank($get('manual_time'))) {
            return false;
        }

        return CarbonImmutable::parse($get('date'))->setTimeFromTimeString($get('manual_time'))->isPast();
    }

    /** Qué se hace: solo lo que realiza el doctor elegido (si ya hay uno). */
    public static function treatmentSelect(?Appointment $moving = null): Select
    {
        return Select::make('treatment_id')
            ->label('Tratamiento')
            ->options(fn (Get $get) => Treatment::query()
                ->active()
                ->when($get('doctor_id'), fn ($q, $doctorId) => $q->whereHas('doctors', fn ($d) => $d->whereKey($doctorId)))
                ->orderBy('sort_order')
                ->pluck('name', 'id'))
            ->default($moving?->treatment_id ?? (request()->integer('treatment') ?: null))
            ->hidden($moving !== null)
            ->dehydrated($moving === null)
            ->required($moving === null)
            ->searchable()
            ->live();
    }

    /** Con quién: solo quienes hacen el tratamiento elegido (si ya hay uno). El doctor solo agenda con él mismo. */
    public static function doctorSelect(?Appointment $moving = null): Select
    {
        /** @var User $user */
        $user = auth()->user();
        $ownDoctorId = $user->managesAllAgendas() ? null : $user->doctorId();

        return Select::make('doctor_id')
            ->label('Doctor')
            ->options(fn (Get $get) => Doctor::query()
                ->active()
                ->when($moving?->treatment_id ?? $get('treatment_id'), fn ($q, $treatmentId) => $q->whereHas('treatments', fn ($t) => $t->whereKey($treatmentId)))
                ->orderBy('sort_order')
                ->pluck('name', 'id'))
            ->default(fn () => $moving?->doctor_id ?? $ownDoctorId ?? (request()->integer('doctor') ?: null) ?? self::onlyActiveDoctorId())
            ->disabled($ownDoctorId !== null)
            ->dehydrated()
            ->required()
            ->live();
    }

    /**
     * El doctor que ya viene puesto en una ventana que llena sus datos a mano: el propio (el doctor solo agenda
     * con él mismo) o, si el consultorio tiene un solo doctor activo, ese.
     */
    public static function defaultDoctorId(): ?int
    {
        /** @var User $user */
        $user = auth()->user();

        return ($user->managesAllAgendas() ? null : $user->doctorId()) ?? self::onlyActiveDoctorId();
    }

    /** Fecha y hora elegidas en el formulario. */
    public static function startsAt(array $data): CarbonImmutable
    {
        $time = ($data['manual'] ?? false) ? $data['manual_time'] : $data['slot'];

        return CarbonImmutable::parse($data['date'])->setTimeFromTimeString($time);
    }

    /**
     * Al elegir al paciente, si suele venir un mismo día, se propone el próximo (salvo que el día se haya
     * elegido en la agenda). Sus horarios de siempre salen primero en la lista.
     */
    public static function followUsualDay(Get $get, Set $set): void
    {
        if (filled($get('date_from_agenda')) || filled($get('preferred_time'))) {
            return;
        }

        $date = self::nextUsualDay(filled($get('patient_id')) ? Patient::find($get('patient_id')) : null);

        if ($date) {
            $set('date', $date->toDateString());
            $set('slot', null);
        }
    }

    /** El próximo día (desde hoy) en que suele venir; null si no tiene un día fijo. */
    public static function nextUsualDay(?Patient $patient): ?CarbonImmutable
    {
        $weekday = $patient ? app(PatientInsights::class)->usualVisit($patient)['weekday'] ?? null : null;

        if ($weekday === null) {
            return null;
        }

        $date = CarbonImmutable::today();

        while ($date->dayOfWeek !== $weekday) {
            $date = $date->addDay();
        }

        return $date;
    }

    /**
     * Cuándo suele venir el paciente elegido.
     *
     * @return array{weekday: int|null, period: DayPeriod|null, label: string}|null
     */
    private static function usualVisit(Get $get): ?array
    {
        $patient = filled($get('patient_id')) ? Patient::find($get('patient_id')) : null;

        return $patient ? app(PatientInsights::class)->usualVisit($patient) : null;
    }

    /** Primer horario libre desde la hora que se tocó en la agenda. */
    private static function suggestedSlot(Get $get, ?Appointment $moving): ?string
    {
        $preferred = $get('preferred_time');

        if (! $preferred) {
            return null;
        }

        return collect(array_keys(self::slotOptions($get, $moving)))
            ->first(fn (string $time) => $time >= $preferred);
    }

    /** "Su corona de zirconio llega del laboratorio el sáb. 14 nov.: si esta cita es para colocarla, agéndala desde ese día." */
    private static function labWorkArrivingAfter(mixed $patientId, mixed $date): ?string
    {
        if (blank($patientId) || blank($date)) {
            return null;
        }

        $order = LabOrder::query()->arrivingAfter((int) $patientId, CarbonImmutable::parse($date))->orderBy('due_on')->first();

        return $order
            ? 'Ojo: "'.$order->description.'" llega del laboratorio el '.$order->due_on->translatedFormat('D j M').'. Si esta cita es para colocarlo, agéndala desde ese día.'
            : null;
    }

    /** Si el consultorio tiene un solo doctor activo, ya viene elegido. */
    private static function onlyActiveDoctorId(): ?int
    {
        $ids = Doctor::query()->active()->limit(2)->pluck('id');

        return $ids->count() === 1 ? $ids->first() : null;
    }

    private static function requestedDate(): ?CarbonImmutable
    {
        $date = (string) request('date');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) && CarbonImmutable::parse($date)->gte(today())
            ? CarbonImmutable::parse($date)
            : null;
    }

    /** Bajo los horarios: qué significa la estrella o, si no hay ninguno, por qué y qué hacer. */
    private static function slotsHint(Get $get, ?Appointment $moving): string
    {
        if (self::slotOptions($get, $moving) !== []) {
            $usual = self::usualVisit($get);

            return ($usual !== null && $usual['period'] !== null ? $usual['label'].': esos horarios van primero. ' : '').'★ = pegado a otra cita (no deja huecos).';
        }

        if (! self::canSearch($get, $moving)) {
            // La hora que se tocó en la agenda no se pierde: se dice, y queda puesta al elegir lo que falta.
            return filled($get('preferred_time'))
                ? 'Elige tratamiento y doctor: queda puesta la primera hora libre desde las '.$get('preferred_time').', la que tocaste en la agenda.'
                : 'Elige tratamiento, doctor y día para ver los horarios libres.';
        }

        $next = self::nextDayWithSlots($get, $moving);

        return $next
            ? 'Ese día no hay horario libre. El siguiente con lugar es el '.$next->translatedFormat('l j \\d\\e F').'.'
            : 'Ni ese día ni en las '.intdiv(GapFinder::SEARCH_DAYS, 7).' semanas siguientes hay horario libre. Usa "Otra hora" o apúntalo en la lista de espera.';
    }

    /** ¿Ya se eligió tratamiento, doctor y día? (entonces, si no hay horarios, es que el día está lleno). */
    private static function canSearch(Get $get, ?Appointment $moving): bool
    {
        return filled($get('doctor_id')) && filled($moving?->treatment_id ?? $get('treatment_id')) && filled($get('date'));
    }

    /** El primer día después del elegido en que el doctor tiene un horario libre para el tratamiento. */
    private static function nextDayWithSlots(Get $get, ?Appointment $moving): ?CarbonImmutable
    {
        $doctor = Doctor::find($get('doctor_id'));
        $treatment = Treatment::find($moving?->treatment_id ?? $get('treatment_id'));

        if (! $doctor || ! $treatment || blank($get('date'))) {
            return null;
        }

        $from = CarbonImmutable::parse($get('date'))->startOfDay();
        $patientId = $moving?->patient_id ?? (filled($get('patient_id')) ? (int) $get('patient_id') : null);

        // Lo piden el texto y el botón en el mismo pedido: se busca una sola vez.
        $memo = 'slot-picker.next-day.'.implode('|', [$doctor->getKey(), $treatment->getKey(), $from->toDateString(), $patientId, $moving?->getKey(), self::chosenDuration($get, $moving)]);

        if (! request()->attributes->has($memo)) {
            $availability = app(AvailabilityService::class)->forPatient($patientId);
            $found = null;

            for ($day = $from->addDay(); $found === null && $day->lte($from->addDays(GapFinder::SEARCH_DAYS)); $day = $day->addDay()) {
                if ($availability->slotsFor($doctor, $treatment, $day, ignoreAppointmentId: $moving?->getKey(), durationMinutes: self::chosenDuration($get, $moving))->isNotEmpty()) {
                    $found = $day;
                }
            }

            request()->attributes->set($memo, $found);
        }

        return request()->attributes->get($memo);
    }

    /**
     * La duración puesta a mano: la que ya tiene la cita que se mueve o la elegida en "Detalles" de la cita nueva.
     * Null, la normal del tratamiento con ese doctor.
     */
    public static function chosenDuration(Get $get, ?Appointment $moving = null): ?int
    {
        if ($moving !== null) {
            return $moving->custom_duration ? $moving->durationMinutes() : null;
        }

        return filled($get('duration_minutes')) ? (int) $get('duration_minutes') : null;
    }

    /**
     * Para elegir cuánto dura una cita: las duraciones de siempre más las que se pidan (la normal de ese
     * tratamiento, la que la cita ya tiene), de menor a mayor.
     *
     * @return array<int, string> Minutos => "45 min"
     */
    public static function durationOptions(?int $usual = null, int ...$include): array
    {
        return collect([10, 15, 20, 30, 40, 45, 60, 75, 90, 105, 120, 150, 180, 210, 240, $usual, ...$include])
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn (int $minutes) => [$minutes => Dates::duration($minutes).($minutes === $usual ? ' (lo normal)' : '')])
            ->all();
    }

    /** Al cambiar la duración, la hora elegida solo se conserva si la cita todavía cabe ahí. */
    public static function keepSlotIfItFits(Get $get, Set $set): void
    {
        if (filled($get('slot')) && ! array_key_exists($get('slot'), self::slotOptions($get, null))) {
            $set('slot', null);
        }
    }

    /** @return array<string, string> */
    private static function slotOptions(Get $get, ?Appointment $moving): array
    {
        $doctor = Doctor::find($get('doctor_id'));
        $treatment = Treatment::find($moving?->treatment_id ?? $get('treatment_id'));
        $date = $get('date');

        if (! $doctor || ! $treatment || ! $date) {
            return [];
        }

        $day = CarbonImmutable::parse($date);

        $usualPeriod = $moving ? null : self::usualVisit($get)['period'] ?? null;

        // Los de la parte del día en que suele venir, primero.
        // El horario apartado para este paciente (de la lista de espera) le sirve; el de otro, no.
        return app(AvailabilityService::class)
            ->forPatient($moving?->patient_id ?? (filled($get('patient_id')) ? (int) $get('patient_id') : null))
            ->slotsFor($doctor, $treatment, $day, earliest: $day->isToday() ? now() : null, ignoreAppointmentId: $moving?->getKey(), durationMinutes: self::chosenDuration($get, $moving))
            ->sortBy(fn (Slot $slot) => $usualPeriod && ! $usualPeriod->includes($slot->startsAt) ? 1 : 0)
            ->mapWithKeys(fn (Slot $slot) => [$slot->label() => $slot->label().($slot->recommended ? ' ★' : '')])
            ->all();
    }
}
