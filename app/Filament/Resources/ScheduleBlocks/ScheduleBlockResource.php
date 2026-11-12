<?php

namespace App\Filament\Resources\ScheduleBlocks;

use App\Enums\ScheduleBlockType;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\ScheduleBlocks\Pages\ManageScheduleBlocks;
use App\Models\Doctor;
use App\Models\ScheduleBlock;
use App\Models\User;
use BackedEnum;
use Carbon\CarbonImmutable;
use Closure;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                DateTimePicker::make('starts_at')
                    ->label('Desde')
                    ->seconds(false)
                    ->minutesStep(5)
                    ->required()
                    ->live(),
                DateTimePicker::make('ends_at')
                    ->label('Hasta')
                    ->seconds(false)
                    ->minutesStep(5)
                    ->required()
                    ->after('starts_at')
                    ->minDate(fn (Get $get) => $get('starts_at'))
                    // Un bloqueo nuevo que ya terminó no bloquea nada: casi siempre es una fecha mal escrita.
                    ->rule(fn () => function (string $attribute, mixed $value, Closure $fail): void {
                        if (filled($value) && CarbonImmutable::parse($value)->isPast()) {
                            $fail('Ese horario ya pasó. Revisa la fecha, porque un bloqueo solo sirve de ahora en adelante.');
                        }
                    }, fn (string $operation) => $operation === 'create'),
                TextInput::make('reason')
                    ->label('Detalle')
                    ->placeholder('Por ejemplo: Congreso de ortodoncia')
                    ->maxLength(150)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('starts_at')
                    ->label('Desde')
                    ->dateTime('D d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('ends_at')
                    ->label('Hasta')
                    ->dateTime('D d/m/Y H:i'),
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
            ])
            ->recordActions([
                EditAction::make()
                    ->mutateDataUsing(fn (array $data) => ManageScheduleBlocks::forOwnAgenda($data))
                    ->after(fn (ScheduleBlock $record) => ManageScheduleBlocks::warnAboutAppointments($record)),
                // Borrar va en el menú ⋮: el rojo queda para lo urgente.
                ActionGroup::make([
                    DeleteAction::make(),
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

    public static function getPages(): array
    {
        return [
            'index' => ManageScheduleBlocks::route('/'),
        ];
    }
}
