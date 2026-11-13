<?php

namespace App\Filament\Resources\ScheduleBlocks;

use App\Enums\ScheduleBlockType;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\ScheduleBlocks\Pages\ManageScheduleBlocks;
use App\Models\Doctor;
use App\Models\ScheduleBlock;
use App\Models\User;
use App\Services\Agenda\BlockScheduler;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/** Horas en que no se atiende (almuerzo, cursos, vacaciones, feriados): la agenda no ofrece esos horarios. */
class ScheduleBlockResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'bloqueos';

    protected static ?string $model = ScheduleBlock::class;

    protected static ?string $modelLabel = 'bloqueo de horario';

    protected static ?string $pluralModelLabel = 'bloqueos de horario';

    protected static ?string $navigationParentItem = 'Agenda';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNoSymbol;

    private const PAST_MESSAGE = 'Ese horario ya pasó. Revisa la fecha, porque un bloqueo solo sirve de ahora en adelante.';

    public static function form(Schema $schema): Schema
    {
        /** @var User $user */
        $user = auth()->user();
        $ownDoctorId = $user->managesAllAgendas() ? null : $user->doctorId();

        return $schema
            ->columns(2)
            ->components([
                Select::make('doctor_id')
                    ->label('Doctor')
                    ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                    ->placeholder('Todo el consultorio')
                    ->default($ownDoctorId)
                    ->disabled($ownDoctorId !== null)
                    ->dehydrated()
                    ->required($ownDoctorId !== null),
                Select::make('type')
                    ->label('Motivo')
                    ->options(ScheduleBlockType::class)
                    ->default(ScheduleBlockType::Other->value)
                    ->required(),
                // Lo que más se bloquea son días enteros (feriados, vacaciones, un curso): solo se eligen las fechas.
                Toggle::make('all_day')
                    ->label('Todo el día')
                    ->helperText(fn (Get $get) => $get('all_day') ? 'Apágalo para bloquear solo unas horas.' : 'Enciéndelo para bloquear días completos.')
                    ->default(true)
                    ->live()
                    // Al pasar a horas, llegan las de la mañana del día que estaba elegido: solo se ajustan.
                    ->afterStateUpdated(function (?bool $state, Get $get, Set $set): void {
                        if (! $state && blank($get('starts_at')) && blank($get('ends_at')) && filled($get('from_day'))) {
                            $day = CarbonImmutable::parse($get('from_day'));

                            $set('starts_at', $day->setTime(9, 0)->toDateTimeString());
                            $set('ends_at', $day->setTime(13, 0)->toDateTimeString());
                        }
                    })
                    ->columnSpanFull(),
                DatePicker::make('from_day')
                    ->label('Desde')
                    ->native(false)
                    ->displayFormat('D d/m/Y')
                    ->firstDayOfWeek(1)
                    ->closeOnDateSelection()
                    ->default(fn () => today()->toDateString())
                    ->required()
                    ->visible(fn (Get $get) => (bool) $get('all_day'))
                    ->live()
                    // Lo normal es un solo día: "Hasta" sigue a "Desde" mientras no quede antes.
                    ->afterStateUpdated(function (?string $state, Get $get, Set $set): void {
                        if (filled($state) && (blank($get('until_day')) || CarbonImmutable::parse($get('until_day'))->lt(CarbonImmutable::parse($state)))) {
                            $set('until_day', $state);
                        }
                    }),
                DatePicker::make('until_day')
                    ->label('Hasta')
                    ->helperText('El mismo día si es uno solo.')
                    ->native(false)
                    ->displayFormat('D d/m/Y')
                    ->firstDayOfWeek(1)
                    ->closeOnDateSelection()
                    ->default(fn () => today()->toDateString())
                    ->required()
                    ->afterOrEqual('from_day')
                    ->minDate(fn (Get $get) => $get('from_day'))
                    ->visible(fn (Get $get) => (bool) $get('all_day'))
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && CarbonImmutable::parse($value)->lt(today())) {
                            $fail(self::PAST_MESSAGE);
                        }
                    }, fn (?ScheduleBlock $record) => $record === null),
                DateTimePicker::make('starts_at')
                    ->label('Desde')
                    ->seconds(false)
                    ->minutesStep(5)
                    ->required()
                    ->visible(fn (Get $get) => ! $get('all_day'))
                    ->live(),
                DateTimePicker::make('ends_at')
                    ->label('Hasta')
                    ->seconds(false)
                    ->minutesStep(5)
                    ->required()
                    ->after('starts_at')
                    ->minDate(fn (Get $get) => $get('starts_at'))
                    ->visible(fn (Get $get) => ! $get('all_day'))
                    // Un bloqueo nuevo que ya terminó no bloquea nada: casi siempre es una fecha mal escrita.
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && CarbonImmutable::parse($value)->isPast()) {
                            $fail(self::PAST_MESSAGE);
                        }
                    }, fn (?ScheduleBlock $record) => $record === null),
                // El curso de todos los jueves, el descanso de los sábados: se crea uno por semana y cada uno se corrige o se borra aparte.
                Select::make('repeat_weeks')
                    ->label('Se repite')
                    ->options([1 => 'No se repite'] + collect([2, 3, 4, 5, 6, 8, 10, 12, 16, 20, BlockScheduler::REPEAT_MAX_WEEKS])
                        ->mapWithKeys(fn (int $weeks) => [$weeks => "Cada semana, {$weeks} semanas seguidas"])
                        ->all())
                    ->default(1)
                    ->selectablePlaceholder(false)
                    ->visible(fn (?ScheduleBlock $record) => $record === null),
                TextInput::make('reason')
                    ->label('Detalle')
                    ->placeholder('Por ejemplo: Congreso de ortodoncia')
                    ->maxLength(150)
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Lo que la ventana de un bloqueo nuevo trae puesto: un día completo (hoy, o el día que se mira en la agenda),
     * sin repetirse, en la agenda propia si quien bloquea es un doctor.
     *
     * @param  array<string, mixed>  $with
     * @return array<string, mixed>
     */
    public static function defaults(array $with = []): array
    {
        /** @var User $user */
        $user = auth()->user();

        return $with + [
            'doctor_id' => $user->managesAllAgendas() ? null : $user->doctorId(),
            'type' => ScheduleBlockType::Other->value,
            'all_day' => true,
            'from_day' => today()->toDateString(),
            'until_day' => $with['from_day'] ?? today()->toDateString(),
            'repeat_weeks' => 1,
        ];
    }

    /**
     * De lo que se llenó en la ventana a lo que guarda el bloqueo: "todo el día" va de las 00:00 del primer día a las
     * 00:00 del día siguiente al último.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function toAttributes(array $data): array
    {
        if ($data['all_day'] ?? false) {
            $data['starts_at'] = CarbonImmutable::parse($data['from_day'])->startOfDay();
            $data['ends_at'] = CarbonImmutable::parse($data['until_day'])->startOfDay()->addDay();
        }

        return Arr::except($data, ['all_day', 'from_day', 'until_day', 'repeat_weeks']);
    }

    /**
     * Lo que la ventana muestra de un bloqueo ya guardado, para corregirlo.
     *
     * @return array<string, mixed>
     */
    public static function formState(ScheduleBlock $block): array
    {
        return [
            'all_day' => $block->isWholeDays(),
            'from_day' => $block->starts_at->toDateString(),
            'until_day' => $block->lastDay()->toDateString(),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                // Un día completo se lee como día: sin "00:00" ni la fecha del día siguiente.
                TextColumn::make('starts_at')
                    ->label('Desde')
                    ->formatStateUsing(fn (ScheduleBlock $record) => $record->starts_at->translatedFormat($record->isWholeDays() ? 'D d/m/Y' : 'D d/m/Y H:i'))
                    ->description(fn (ScheduleBlock $record) => $record->isWholeDays() ? 'Todo el día' : null)
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Hasta')
                    ->formatStateUsing(fn (ScheduleBlock $record) => $record->isWholeDays()
                        ? $record->lastDay()->translatedFormat('D d/m/Y')
                        : $record->ends_at->translatedFormat('D d/m/Y H:i')),
                TextColumn::make('doctor.name')
                    ->label('Doctor')
                    ->placeholder('Todo el consultorio'),
                TextColumn::make('type')
                    ->label('Motivo')
                    ->badge()
                    ->color('gray')
                    ->description(fn (ScheduleBlock $record) => $record->reason),
                // Las citas que siguen dentro del bloqueo: hasta moverlas, esos pacientes vendrían igual.
                TextColumn::make('appointments_inside')
                    ->label('Citas dentro')
                    ->state(fn (ScheduleBlock $record) => match ($count = (int) $record->appointments_inside) {
                        0 => null,
                        1 => '1 cita por mover',
                        default => "{$count} citas por mover",
                    })
                    ->badge()
                    ->color('warning')
                    ->placeholder('—')
                    ->url(fn (?string $state) => $state ? ManageScheduleBlocks::appointmentsInsideUrl() : null),
            ])
            ->defaultSort('starts_at')
            ->filters([
                Filter::make('upcoming')
                    ->label('Solo vigentes y próximos')
                    ->toggle()
                    ->default()
                    ->query(fn (Builder $query) => $query->where('ends_at', '>=', now())),
                // Lo que le quita horas a un doctor: sus bloqueos y los de todo el consultorio (feriados). Desde la
                // ficha del doctor se llega aquí con este filtro puesto.
                SelectFilter::make('doctor')
                    ->label('Doctor')
                    ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                    ->query(fn (Builder $query, array $data) => $query->when(
                        filled($data['value'] ?? null),
                        fn (Builder $blocks) => $blocks->where(fn (Builder $own) => $own->whereNull('doctor_id')->orWhere('doctor_id', $data['value'])),
                    ))
                    ->visible(fn () => auth()->user()->managesAllAgendas()),
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data, ScheduleBlock $record) => self::formState($record) + $data)
                    ->mutateDataUsing(fn (array $data) => ManageScheduleBlocks::forOwnAgenda(self::toAttributes($data)))
                    ->after(fn (ScheduleBlock $record) => ManageScheduleBlocks::warnAboutAppointments($record)),
                // Borrar va en el menú ⋮: el rojo queda para lo urgente.
                ActionGroup::make([
                    DeleteAction::make()
                        ->modalDescription('Ese horario vuelve a quedar libre: la agenda y la web lo ofrecen otra vez.'),
                ]),
            ])
            ->emptyStateHeading('No hay bloqueos próximos')
            ->emptyStateDescription('Agrega vacaciones, cursos o feriados para que la agenda no ofrezca esas horas.');
    }

    /** Cada doctor ve los bloqueos de su agenda y los de todo el consultorio, con cuántas citas siguen dentro. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with('doctor')
            ->addSelect(['appointments_inside' => ManageScheduleBlocks::appointmentsInsideCount()])
            ->when(! $user->managesAllAgendas(), fn (Builder $q) => $q->where(fn (Builder $w) => $w->whereNull('doctor_id')->orWhere('doctor_id', $user->doctorId())));
    }

    /** La lista con los bloqueos que le quitan horas a ese doctor (los suyos y los de todo el consultorio), vigentes y próximos. */
    public static function urlForDoctor(Doctor $doctor): string
    {
        return self::getUrl('index', ['filters' => ['upcoming' => ['isActive' => true], 'doctor' => ['value' => $doctor->getKey()]]]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageScheduleBlocks::route('/'),
        ];
    }
}
