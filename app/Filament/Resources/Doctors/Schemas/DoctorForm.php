<?php

namespace App\Filament\Resources\Doctors\Schemas;

use App\Enums\UserRole;
use App\Enums\WebPresence;
use App\Filament\Forms\ColorSwatches;
use App\Filament\Forms\WebPhotoUpload;
use App\Filament\Forms\WeeklySchedule;
use App\Filament\Resources\ScheduleBlocks\ScheduleBlockResource;
use App\Models\Doctor;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class DoctorForm
{
    private const ACCESS_NEW = 'new';

    private const ACCESS_EXISTING = 'existing';

    private const ACCESS_NONE = 'none';

    /** Colores que se distinguen bien entre sí en la agenda, con el nombre con que se eligen. */
    private const AGENDA_COLORS = [
        '#0ea5e9' => 'Celeste', '#10b981' => 'Verde', '#f59e0b' => 'Ámbar', '#8b5cf6' => 'Violeta',
        '#ec4899' => 'Rosa', '#14b8a6' => 'Turquesa', '#f97316' => 'Naranja', '#64748b' => 'Gris',
    ];

    public const WEEKDAYS = WeeklySchedule::DAYS;

    /**
     * "Copiar este día a otros": arma el lunes (mañana y tarde) y lo repite en los días que se marquen, en vez
     * de llenar tramo por tramo. Lo que esos días tenían se reemplaza; se guarda al guardar el doctor.
     */
    private static function copyDayAction(): Action
    {
        $weekdayOf = fn (array $arguments, Repeater $component): int => (int) ($component->getRawItemState($arguments['item'])['weekday'] ?? 0);

        return Action::make('copyDay')
            ->label('Copiar a otros días')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->link()
            ->size(Size::Small)
            ->modalHeading(fn (array $arguments, Repeater $component) => 'Copiar el horario del '.Str::lower(self::WEEKDAYS[$weekdayOf($arguments, $component)] ?? 'día'))
            ->modalDescription('Todos sus tramos pasan a los días que marques y reemplazan lo que esos días tenían.')
            ->modalSubmitActionLabel('Copiar')
            ->modalWidth('md')
            ->schema(fn (array $arguments, Repeater $component) => [
                CheckboxList::make('days')
                    ->label('A estos días')
                    ->options(Arr::except(self::WEEKDAYS, [$weekdayOf($arguments, $component)]))
                    ->default(array_values(array_diff([1, 2, 3, 4, 5], [$weekdayOf($arguments, $component)])))
                    ->columns(2)
                    ->required(),
            ])
            ->action(function (array $arguments, array $data, Repeater $component) use ($weekdayOf) {
                $weekday = $weekdayOf($arguments, $component);
                $days = array_map('intval', $data['days']);
                $items = collect($component->getRawState() ?? []);
                $source = $items->filter(fn (array $item) => (int) ($item['weekday'] ?? 0) === $weekday)->values();
                $copies = collect($days)
                    ->flatMap(fn (int $day) => $source->map(fn (array $item) => ['weekday' => $day, ...Arr::only($item, ['starts_at', 'ends_at', 'valid_from', 'valid_until'])]))
                    ->mapWithKeys(fn (array $item) => [$component->generateUuid() ?? (string) Str::uuid() => $item]);

                $component->rawState($items
                    ->reject(fn (array $item) => in_array((int) ($item['weekday'] ?? 0), $days, true))
                    ->union($copies)
                    ->sortBy(fn (array $item) => sprintf('%d %s', $item['weekday'] ?? 0, $item['starts_at'] ?? ''))
                    ->all());
                $component->callAfterStateUpdated();

                Notification::make()
                    ->success()
                    ->title('Listo: '.Str::lower(collect($days)->sort()->map(fn (int $day) => self::WEEKDAYS[$day])->join(', ', ' y '))
                        .(count($days) === 1 ? ' queda' : ' quedan').' como el '.Str::lower(self::WEEKDAYS[$weekday]).'.')
                    ->body('Revisa y guarda los cambios.')
                    ->send();
            });
    }

    /**
     * Crea el usuario del doctor si se pidió "Crear su acceso" (con su nombre y el rol de doctor) y lo deja
     * enlazado. Sin acceso, el doctor queda sin usuario.
     *
     * @param  array<string, mixed>  $data  Lo que se guarda del doctor.
     * @param  array<string, mixed>  $raw  El formulario completo, con lo del acceso.
     * @return array<string, mixed>
     */
    public static function withAccess(array $data, array $raw): array
    {
        $data = self::withPresence($data);
        $data['user_id'] = match ($raw['access'] ?? null) {
            self::ACCESS_NEW => User::create([
                'name' => $data['name'],
                'email' => $raw['access_email'],
                'password' => $raw['access_password'],
                // Se la puso el administrador: al entrar, el doctor elige la suya.
                'must_change_password' => true,
                'role' => UserRole::Doctor,
                'is_active' => true,
            ])->getKey(),
            self::ACCESS_NONE => null,
            default => $data['user_id'] ?? null,
        };

        return $data;
    }

    /**
     * "Puede entrar al panel", apagado o encendido en la ficha del doctor, pasa a su usuario. Nadie se quita el
     * acceso a sí mismo, y si en la misma guardada se le cambió de usuario, no se toca ninguno.
     *
     * @param  array<string, mixed>  $raw  El formulario completo, con lo del acceso.
     */
    public static function applyPanelAccess(Doctor $doctor, array $raw): void
    {
        $user = $doctor->user()->first();

        if ($user === null || $user->is(auth()->user()) || ! array_key_exists('can_enter', $raw) || (int) ($raw['user_id'] ?? 0) !== $user->getKey()) {
            return;
        }

        $user->update(['is_active' => (bool) $raw['can_enter']]);
    }

    /**
     * Lo elegido en "Dónde aparece" pasa a lo que el sistema guarda: si sale en el equipo de la web y si recibe
     * las reservas en línea.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withPresence(array $data): array
    {
        $presence = WebPresence::tryFrom((string) Arr::pull($data, 'presence'));

        return $presence === null ? $data : [...$data, 'is_public' => $presence->isPublic(), 'accepts_online_booking' => $presence->isBookable()];
    }

    /** Lo marcado en "Dónde aparece" mientras se llena la ficha. */
    private static function presenceOf(Get $get): WebPresence
    {
        return WebPresence::tryFrom((string) $get('presence')) ?? WebPresence::Clinic;
    }

    /**
     * El primer par de tramos del mismo día que se cruzan o se repiten, dicho para corregirlo:
     * "El lunes tiene dos tramos que se cruzan: 09:00–13:00 y 12:00–16:00…". Null si ninguno se cruza. Los que
     * valen en temporadas distintas no se cruzan.
     *
     * @param  array<array-key, array<string, mixed>>  $ranges  Los tramos del formulario.
     */
    public static function overlappingRanges(array $ranges): ?string
    {
        // La hora llega como "09:00", "09:00:00" o, recién elegida en pantalla, con la fecha delante ("2026-11-13 09:00:00").
        $clock = fn (mixed $time): string => preg_match('/(\d{1,2}:\d{2})(:\d{2})?$/', trim((string) $time), $found) ? str_pad($found[1], 5, '0', STR_PAD_LEFT) : '';
        $byDay = collect($ranges)
            ->filter(fn (mixed $range) => is_array($range) && filled($range['weekday'] ?? null) && filled($range['starts_at'] ?? null) && filled($range['ends_at'] ?? null))
            ->groupBy(fn (array $range) => (int) $range['weekday']);

        // Dos tramos solo chocan si alguna fecha cae en los dos: el de verano y el del resto del año pueden convivir.
        $together = fn (array $one, array $other): bool => (WeeklySchedule::dateOnly($one['valid_from'] ?? null) ?? '0000-00-00') <= (WeeklySchedule::dateOnly($other['valid_until'] ?? null) ?? '9999-12-31')
            && (WeeklySchedule::dateOnly($other['valid_from'] ?? null) ?? '0000-00-00') <= (WeeklySchedule::dateOnly($one['valid_until'] ?? null) ?? '9999-12-31');

        foreach ($byDay as $weekday => $day) {
            $sorted = $day->sortBy(fn (array $range) => $clock($range['starts_at']))->values();

            foreach ($sorted as $index => $range) {
                foreach ($sorted->slice(0, $index) as $earlier) {
                    if ($clock($range['starts_at']) < $clock($earlier['ends_at']) && $together($earlier, $range)) {
                        $seasonal = filled($earlier['valid_from'] ?? null) || filled($earlier['valid_until'] ?? null) || filled($range['valid_from'] ?? null) || filled($range['valid_until'] ?? null);

                        return 'El '.Str::lower(self::WEEKDAYS[$weekday] ?? 'día').' tiene dos tramos que se cruzan: '
                            .$clock($earlier['starts_at']).'–'.$clock($earlier['ends_at']).' y '.$clock($range['starts_at']).'–'.$clock($range['ends_at'])
                            .($seasonal ? '. Deja uno solo, cámbiales la hora o ponles temporadas que no coincidan.' : '. Deja uno solo o cámbiales la hora.');
                    }
                }
            }
        }

        return null;
    }

    /**
     * Lo que le falta a un doctor para que se le pueda agendar: "su horario" y "los tratamientos que realiza".
     *
     * @return list<string>
     */
    public static function missingToAttend(Doctor $doctor): array
    {
        return array_values(array_filter([
            $doctor->schedules()->exists() ? null : 'su horario',
            $doctor->treatments()->exists() ? null : 'los tratamientos que realiza',
        ]));
    }

    /** Lo que pasa al desactivar a un doctor, con las citas que le quedan por atender. */
    public static function deactivationWarning(Doctor $doctor): string
    {
        $pending = $doctor->appointmentsToAttend()->count();

        return 'Deja de aparecer en la web y ya no se le pueden dar citas nuevas. '.match (true) {
            $pending === 0 => 'No tiene citas por atender.',
            $pending === 1 => 'Tiene 1 cita por atender: sigue en la agenda hasta que se atienda, se cambie o se cancele.',
            default => "Tiene {$pending} citas por atender: siguen en la agenda hasta que se atiendan, se cambien o se cancelen.",
        };
    }

    /** El primer color de la agenda que ningún doctor usa todavía, para distinguirlos de un vistazo. */
    private static function unusedColor(): string
    {
        $used = Doctor::withTrashed()->pluck('agenda_color')->map(fn (?string $color) => strtolower((string) $color))->all();

        return collect(array_keys(self::AGENDA_COLORS))->first(fn (string $color) => ! in_array($color, $used, true)) ?? array_key_first(self::AGENDA_COLORS);
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                // Mientras le falte horario o tratamientos no se le puede dar citas: se dice arriba de su ficha.
                Text::make(fn (?Doctor $record) => 'Todavía no se le puede agendar: falta '.implode(' y ', $record ? self::missingToAttend($record) : []).'.')
                    ->color('warning')
                    ->weight(FontWeight::Medium)
                    ->visible(fn (?Doctor $record) => $record !== null && self::missingToAttend($record) !== []),
                Section::make('Perfil')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre como lo verá el paciente')
                            ->placeholder('Dra. Ana Torres')
                            ->required()
                            ->maxLength(100)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                        TextInput::make('specialty')
                            ->label('Especialidad')
                            ->placeholder('Odontología general'),
                        TextInput::make('cop_number')
                            ->label('COP'),
                        TextInput::make('rne_number')
                            ->label('RNE (especialista)'),
                        // Ocho botones con nombre en vez de un código como "#0ea5e9". Viene uno que todavía no usa otro doctor.
                        ColorSwatches::make('agenda_color')
                            ->label('Color en la agenda')
                            ->swatches(self::AGENDA_COLORS)
                            ->default(fn () => self::unusedColor())
                            ->required()
                            ->helperText('Con este color salen sus citas en la agenda.')
                            ->columnSpanFull(),
                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->live()
                            ->helperText(fn (Get $get, ?Doctor $record) => $record && ! $get('is_active') ? self::deactivationWarning($record) : null)
                            ->columnSpanFull(),
                    ]),
                // Todo lo de la web en un solo bloque, con una sola pregunta en vez de dos interruptores que se pisaban
                // ("Aparece en la web" y "Recibe reservas desde la web").
                Section::make('Página web')
                    ->columns(2)
                    ->schema([
                        // Un doctor nuevo nace fuera de la web: recién guardado no tiene foto, horario ni tratamientos,
                        // y así salía en la página del equipo. Se cambia cuando su ficha está completa.
                        Radio::make('presence')
                            ->label('Dónde aparece')
                            ->options(fn (?Doctor $record) => collect(WebPresence::choices($record?->webPresence()))
                                ->mapWithKeys(fn (WebPresence $presence) => [$presence->value => $presence->getLabel()])
                                ->all())
                            ->descriptions([
                                WebPresence::Clinic->value => 'Atiende y se le agenda desde el panel. No sale en la página web.',
                                WebPresence::Web->value => 'Sale en el equipo de la página web. Sus citas se dan desde el panel.',
                                WebPresence::Booking->value => 'Además, los pacientes pueden reservar con él o ella desde la web.',
                                WebPresence::BookingOnly->value => 'Como estaba: recibe reservas de la web, pero no sale en el equipo.',
                            ])
                            ->default(WebPresence::Clinic->value)
                            ->afterStateHydrated(fn (Radio $component, ?Doctor $record) => $record ? $component->state($record->webPresence()->value) : null)
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get, string $operation) => match (true) {
                                ! $get('is_active') => new HtmlString('<span class="help-warning">Está inactivo: no aparece en ningún lado. Al activarlo, vuelve a aparecer así.</span>'),
                                $operation === 'create' => 'Pásalo a la página web cuando su ficha esté completa: foto, horario y tratamientos.',
                                default => null,
                            })
                            ->columnSpanFull(),
                        // La dirección sale sola del nombre: solo se muestra a quien aparece en la web.
                        TextInput::make('slug')
                            ->label('Dirección en la web')
                            ->prefix('/equipo#')
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true)
                            ->helperText('Sale sola del nombre. Cámbiala solo si quieres otra.')
                            ->visible(fn (Get $get) => self::presenceOf($get) !== WebPresence::Clinic)
                            ->columnSpanFull(),
                        WebPhotoUpload::make('photo_path', 'equipo')
                            ->label('Foto para la web')
                            ->avatar()
                            ->imageEditor()
                            ->circleCropper()
                            ->helperText('De frente, con buena luz. Si no hay foto, la web muestra sus iniciales.'),
                        Textarea::make('bio')
                            ->label('Presentación para la web')
                            ->rows(4)
                            ->helperText('En primera persona y cercana: a quién atiende y cómo trabaja.'),
                        Textarea::make('education')
                            ->label('Formación')
                            ->rows(3)
                            ->placeholder("Cirujano dentista, Universidad …\nEspecialidad en …")
                            ->helperText('Una línea por estudio.')
                            ->columnSpanFull(),
                    ]),
                // Un solo alta: el doctor y su usuario para entrar al panel, sin ir antes a Usuarios.
                Section::make('Acceso al panel')
                    ->description('Con su acceso, el doctor entra a ver su agenda, sus pacientes y la historia clínica.')
                    ->columns(2)
                    ->schema([
                        ToggleButtons::make('access')
                            ->hiddenLabel()
                            ->options(fn (?Doctor $record) => $record?->user_id
                                ? [self::ACCESS_EXISTING => 'Entra con su usuario']
                                : [self::ACCESS_NEW => 'Crear su acceso', self::ACCESS_EXISTING => 'Ya tiene usuario', self::ACCESS_NONE => 'Sin acceso por ahora'])
                            ->default(fn () => request()->integer('user') ? self::ACCESS_EXISTING : self::ACCESS_NEW)
                            ->afterStateHydrated(fn (ToggleButtons $component, ?Doctor $record) => $record ? $component->state($record->user_id ? self::ACCESS_EXISTING : self::ACCESS_NONE) : null)
                            ->inline()
                            ->live()
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        // Quitarle el acceso sin ir a Usuarios: es el mismo interruptor que tiene su usuario.
                        Toggle::make('can_enter')
                            ->label('Puede entrar al panel')
                            ->helperText(fn (?Doctor $record) => $record?->user_id === auth()->id()
                                ? 'Es tu propio usuario: no puedes quitarte el acceso.'
                                : 'Apagado, ya no entra con su correo y su contraseña. Sigue como doctor en la agenda: para que no se le den citas, apaga "Activo".')
                            ->afterStateHydrated(fn (Toggle $component, ?Doctor $record) => $component->state((bool) $record?->user()->value('is_active')))
                            ->disabled(fn (?Doctor $record) => $record?->user_id === auth()->id())
                            ->visible(fn (?Doctor $record, Get $get) => $record?->user_id !== null && (int) $get('user_id') === $record->user_id)
                            ->dehydrated(false)
                            ->columnSpanFull(),
                        TextInput::make('access_email')
                            ->label('Correo para entrar')
                            ->email()
                            ->required()
                            ->unique(User::class, 'email', ignoreRecord: false)
                            ->visible(fn (Get $get) => $get('access') === self::ACCESS_NEW)
                            ->dehydrated(false),
                        TextInput::make('access_password')
                            ->label('Contraseña')
                            ->password()
                            ->revealable()
                            ->minLength(8)
                            ->rule(Password::default())
                            ->required()
                            ->helperText('Mínimo 8 caracteres, con letras y números. Es solo para su primer ingreso: al entrar, el panel le pide elegir la suya.')
                            ->visible(fn (Get $get) => $get('access') === self::ACCESS_NEW)
                            ->dehydrated(false),
                        Select::make('user_id')
                            ->label('Usuario del panel')
                            ->helperText('Por ejemplo, la administradora que también atiende.')
                            ->options(fn (?Doctor $record) => User::query()
                                ->where(fn (Builder $q) => $q->where('role', UserRole::Doctor)->orWhere(fn (Builder $a) => $a->where('role', UserRole::Admin)->where('is_dentist', true)))
                                ->where(fn (Builder $q) => $q->whereDoesntHave('doctor')->when($record, fn (Builder $w) => $w->orWhere('id', $record->user_id)))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->default(fn () => request()->integer('user') ?: null)
                            ->searchable()
                            ->required(fn (Get $get) => $get('access') === self::ACCESS_EXISTING)
                            ->visible(fn (Get $get) => $get('access') === self::ACCESS_EXISTING)
                            ->dehydratedWhenHidden(),
                    ]),
                Section::make('Horario de atención')
                    ->description('Una fila por día. Puedes poner varios tramos (por ejemplo, mañana y tarde): el almuerzo es el espacio entre uno y otro. Con el calendario de cada tramo se le pone una temporada, como el horario de verano. Se guarda con "Guardar cambios".')
                    // Cursos, vacaciones y feriados no van aquí: se llega a los bloqueos de este doctor con un enlace.
                    ->headerActions([
                        Action::make('blocks')
                            ->label('Vacaciones, permisos y feriados')
                            ->link()
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->url(fn (?Doctor $record) => $record ? ScheduleBlockResource::urlForDoctor($record) : ScheduleBlockResource::getUrl('index'))
                            ->visible(fn () => ScheduleBlockResource::canAccess()),
                    ])
                    ->schema([
                        WeeklySchedule::make('schedules')
                            ->hiddenLabel()
                            ->relationship(modifyQueryUsing: fn (Builder $query) => $query->orderBy('weekday')->orderBy('starts_at'))
                            ->schema([
                                Hidden::make('weekday')
                                    ->required(),
                                // Solo por una temporada (horario de verano): se ponen con el botón del calendario de cada tramo.
                                Hidden::make('valid_from')
                                    ->afterStateHydrated(fn (Hidden $component, mixed $state) => $component->state(WeeklySchedule::dateOnly($state))),
                                Hidden::make('valid_until')
                                    ->afterStateHydrated(fn (Hidden $component, mixed $state) => $component->state(WeeklySchedule::dateOnly($state))),
                                TimePicker::make('starts_at')
                                    ->label('Desde')
                                    ->hiddenLabel()
                                    ->seconds(false)
                                    ->required(),
                                TimePicker::make('ends_at')
                                    ->label('Hasta')
                                    ->hiddenLabel()
                                    ->seconds(false)
                                    ->after('starts_at')
                                    ->required(),
                            ])
                            // Dos tramos del mismo día no se pueden cruzar: la agenda ofrecía la misma hora dos veces.
                            ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                                if (($overlap = self::overlappingRanges(is_array($value) ? $value : [])) !== null) {
                                    $fail($overlap);
                                }
                            })
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->extraItemActions([self::copyDayAction()]),
                    ]),
            ]);
    }
}
