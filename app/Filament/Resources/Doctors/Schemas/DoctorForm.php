<?php

namespace App\Filament\Resources\Doctors\Schemas;

use App\Enums\UserRole;
use App\Filament\Forms\WebPhotoUpload;
use App\Models\Doctor;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
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
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class DoctorForm
{
    private const ACCESS_NEW = 'new';

    private const ACCESS_EXISTING = 'existing';

    private const ACCESS_NONE = 'none';

    /** Colores que se distinguen bien entre sí en la agenda. */
    private const AGENDA_COLORS = ['#0ea5e9', '#10b981', '#f59e0b', '#8b5cf6', '#ec4899', '#14b8a6', '#f97316', '#64748b'];

    public const WEEKDAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    /**
     * "Copiar este día a otros": arma el lunes (mañana y tarde) y lo repite en los días que se marquen, en vez
     * de llenar tramo por tramo. Lo que esos días tenían se reemplaza; se guarda al guardar el doctor.
     */
    private static function copyDayAction(): Action
    {
        $weekdayOf = fn (array $arguments, Repeater $component): int => (int) ($component->getRawItemState($arguments['item'])['weekday'] ?? 0);

        return Action::make('copyDay')
            ->label('Copiar este día a otros')
            ->tooltip('Copiar este día a otros')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
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
                    ->flatMap(fn (int $day) => $source->map(fn (array $item) => ['weekday' => $day, 'starts_at' => $item['starts_at'] ?? null, 'ends_at' => $item['ends_at'] ?? null]))
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
        $data['user_id'] = match ($raw['access'] ?? null) {
            self::ACCESS_NEW => User::create([
                'name' => $data['name'],
                'email' => $raw['access_email'],
                'password' => $raw['access_password'],
                'role' => UserRole::Doctor,
                'is_active' => true,
            ])->getKey(),
            self::ACCESS_NONE => null,
            default => $data['user_id'] ?? null,
        };

        return $data;
    }

    /**
     * El primer par de tramos del mismo día que se cruzan o se repiten, dicho para corregirlo:
     * "El lunes tiene dos tramos que se cruzan: 09:00–13:00 y 12:00–16:00…". Null si ninguno se cruza.
     *
     * @param  array<array-key, array<string, mixed>>  $ranges  Los tramos del formulario.
     */
    public static function overlappingRanges(array $ranges): ?string
    {
        $clock = fn (mixed $time): string => substr((string) $time, 0, 5);
        $byDay = collect($ranges)
            ->filter(fn (mixed $range) => is_array($range) && filled($range['weekday'] ?? null) && filled($range['starts_at'] ?? null) && filled($range['ends_at'] ?? null))
            ->groupBy(fn (array $range) => (int) $range['weekday']);

        foreach ($byDay as $weekday => $day) {
            $previous = null;

            foreach ($day->sortBy(fn (array $range) => $clock($range['starts_at']))->values() as $range) {
                if ($previous !== null && $clock($range['starts_at']) < $clock($previous['ends_at'])) {
                    return 'El '.Str::lower(self::WEEKDAYS[$weekday] ?? 'día').' tiene dos tramos que se cruzan: '
                        .$clock($previous['starts_at']).'–'.$clock($previous['ends_at']).' y '.$clock($range['starts_at']).'–'.$clock($range['ends_at'])
                        .'. Deja uno solo o cámbiales la hora.';
                }

                $previous = $previous !== null && $clock($previous['ends_at']) > $clock($range['ends_at']) ? $previous : $range;
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

        return collect(self::AGENDA_COLORS)->first(fn (string $color) => ! in_array($color, $used, true)) ?? self::AGENDA_COLORS[0];
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
                        TextInput::make('slug')
                            ->label('Dirección en la web')
                            ->prefix('/equipo#')
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        TextInput::make('specialty')
                            ->label('Especialidad')
                            ->placeholder('Odontología general'),
                        TextInput::make('cop_number')
                            ->label('COP'),
                        TextInput::make('rne_number')
                            ->label('RNE (especialista)'),
                        // Sin color elegido no se podía guardar: viene uno que todavía no usa otro doctor.
                        ColorPicker::make('agenda_color')
                            ->label('Color en la agenda')
                            ->default(fn () => self::unusedColor())
                            ->required(),
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
                        // Un doctor nuevo nace fuera de la web: recién guardado no tiene foto, horario ni tratamientos,
                        // y así salía en la página del equipo. Se enciende cuando su ficha está completa.
                        Toggle::make('accepts_online_booking')
                            ->label('Recibe reservas desde la web')
                            ->default(false),
                        Toggle::make('is_public')
                            ->label('Aparece en la web')
                            ->default(false)
                            ->helperText(fn (string $operation) => $operation === 'create' ? 'Enciende estas dos opciones cuando su ficha esté completa: foto, horario y tratamientos.' : null),
                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true)
                            ->live()
                            ->helperText(fn (Get $get, ?Doctor $record) => $record && ! $get('is_active') ? self::deactivationWarning($record) : null),
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
                            ->helperText('Mínimo 8 caracteres, con letras y números. Después la cambia en "Mi perfil".')
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
                    ->description('Puedes poner varios tramos por día (por ejemplo, mañana y tarde): el almuerzo es el espacio entre uno y otro. Cursos, vacaciones y feriados van en "Bloqueos de horario".')
                    ->schema([
                        Repeater::make('schedules')
                            ->hiddenLabel()
                            ->relationship(modifyQueryUsing: fn (Builder $query) => $query->orderBy('weekday')->orderBy('starts_at'))
                            ->columns(3)
                            ->schema([
                                Select::make('weekday')
                                    ->label('Día')
                                    ->options(self::WEEKDAYS)
                                    ->required(),
                                TimePicker::make('starts_at')
                                    ->label('Desde')
                                    ->seconds(false)
                                    ->required(),
                                TimePicker::make('ends_at')
                                    ->label('Hasta')
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
                            ->addActionLabel('Agregar tramo')
                            ->reorderable(false)
                            ->extraItemActions([self::copyDayAction()])
                            ->itemLabel(fn (array $state) => self::WEEKDAYS[$state['weekday'] ?? 0] ?? null),
                    ]),
            ]);
    }
}
