<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\AppointmentActions;
use App\Filament\Support\VisitFlags;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Treatment;
use App\Models\User;
use App\Support\Money;
use App\Support\Phone;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();
        $showDoctor = self::showsDoctor($user);

        return $table
            ->columns(self::columns(showDoctor: $showDoctor))
            // Lo que viene, en orden de llegada; en "Todas" y "Sin cerrar", lo más reciente arriba.
            ->defaultSort(fn (Builder $query, HasTable $livewire) => in_array($livewire->activeTab ?? null, ['all', 'unclosed'], true)
                ? $query->orderByDesc('starts_at')->orderByDesc('id')
                : $query->orderBy('starts_at')->orderBy('id'))
            ->filters([
                Filter::make('dates')
                    ->label('Fecha')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y')->firstDayOfWeek(1)->closeOnDateSelection(),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y')->firstDayOfWeek(1)->closeOnDateSelection(),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data) => $query
                        ->when(self::day($data['from'] ?? null), fn (Builder $q, string $date) => $q->whereDate('starts_at', '>=', $date))
                        ->when(self::day($data['until'] ?? null), fn (Builder $q, string $date) => $q->whereDate('starts_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $from = self::day($data['from'] ?? null);
                        $until = self::day($data['until'] ?? null);

                        if (! $from && ! $until) {
                            return [];
                        }

                        $label = match (true) {
                            $from === today()->toDateString() && $until === today()->addDay()->toDateString() => 'Hoy y mañana',
                            $from === $until => 'Día '.now()->parse($from)->format('d/m/Y'),
                            ! $until => 'Desde el '.now()->parse($from)->format('d/m/Y'),
                            ! $from => 'Hasta el '.now()->parse($until)->format('d/m/Y'),
                            default => 'Del '.now()->parse($from)->format('d/m/Y').' al '.now()->parse($until)->format('d/m/Y'),
                        };

                        return [Indicator::make($label)->removeField('from')->removeField('until')];
                    }),
                SelectFilter::make('doctor_id')
                    ->label('Doctor')
                    ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                    ->visible($showDoctor),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(AppointmentStatus::class)
                    ->multiple(),
                SelectFilter::make('treatment_id')
                    ->label('Tratamiento')
                    ->options(fn () => Treatment::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Filter::make('new_patients')
                    ->label('Solo pacientes nuevos')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('is_new_patient', true)),
                // Con él llega el aviso "citas sin confirmar": la lista muestra las mismas que cuenta el aviso.
                Filter::make('to_call')
                    ->label('Solo las que toca llamar')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNotNull('unconfirmed_flagged_at')),
                // Con él llega el aviso de un bloqueo recién guardado: las citas que quedaron dentro y hay que mover.
                Filter::make('in_block')
                    ->label('Solo las que caen en un bloqueo')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->insideABlock()),
            ])
            ->recordActions(AppointmentActions::all())
            ->toolbarActions([AppointmentActions::noShowBulk()])
            ->stackedOnMobile()
            ->poll('30s')
            ->emptyStateHeading('No hay citas')
            ->emptyStateDescription('Usa "Nueva cita" para agendar una.');
    }

    /** La columna "Doctor", para quien ve todas las agendas y solo si hay más de un doctor. */
    public static function showsDoctor(User $user): bool
    {
        return $user->managesAllAgendas() && Doctor::severalActive();
    }

    /**
     * Columnas compartidas con la tabla de "Hoy".
     *
     * @return list<TextColumn>
     */
    public static function columns(bool $showDoctor = true, bool $timeOnly = false): array
    {
        return [
            TextColumn::make('starts_at')
                ->label($timeOnly ? 'Hora' : 'Fecha y hora')
                // Con el año cuando no es de este: en "Todas", un "lun. 01/12" del año pasado parecía del mes que viene.
                ->formatStateUsing(fn (Appointment $record) => $record->starts_at->translatedFormat(match (true) {
                    $timeOnly => 'H:i',
                    $record->starts_at->isCurrentYear() => 'D d/m H:i',
                    default => 'D d/m/Y H:i',
                }))
                ->description(fn (Appointment $record) => $record->ends_at->diffInMinutes($record->starts_at, true).' min')
                ->sortable(),
            TextColumn::make('patient.first_name')
                ->label('Paciente')
                ->formatStateUsing(fn (Appointment $record) => $record->patient->full_name)
                ->description(fn (Appointment $record) => $record->patient->account ? Phone::display($record->patient->account->phone) : null)
                ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn (Builder $p) => $p->search($search)))
                // En una laptop, un nombre largo baja de línea antes de sacar de la pantalla los botones de la fila.
                ->extraCellAttributes(['class' => 'wraps-name']),
            TextColumn::make('treatment.name')
                ->label('Tratamiento')
                ->wrap(),
            TextColumn::make('doctor.name')
                ->label('Doctor')
                ->wrap()
                ->visible($showDoctor),
            TextColumn::make('status')
                ->label('Estado')
                ->badge(),
            self::flagsColumn(),
        ];
    }

    /** El calendario del panel guarda "2026-11-01 00:00:00": para filtrar por día basta "2026-11-01". */
    private static function day(?string $value): ?string
    {
        return filled($value) ? CarbonImmutable::parse($value)->toDateString() : null;
    }

    /**
     * Avisos de la cita: llamar, paciente nuevo, fecha fija, sobrecupo, si es de una serie que se repite, alerta
     * médica (para quien ve lo clínico), pidió cambio, si suele faltar, qué sesión de su plan es y, si se pasa el
     * saldo, cuánto debe.
     */
    public static function flagsColumn(bool $withBalance = false): TextColumn
    {
        return TextColumn::make('flags')
            ->label('')
            ->state(fn (Appointment $record, HasTable $livewire) => array_values(array_filter([
                $record->needsCall() ? 'Llamar' : null,
                ...VisitFlags::labels(VisitFlags::of($record, auth()->user(), $livewire->getTableRecords())),
                $record->is_new_patient ? 'Nuevo' : null,
                $record->fixed_date ? 'Fecha fija' : null,
                $record->is_overbooked ? 'Sobrecupo' : null,
                $record->series_id !== null ? 'Se repite' : null,
                $withBalance && (float) $record->patient->balance_due > 0 ? 'Debe '.Money::short($record->patient->balance_due) : null,
            ])))
            ->badge()
            ->color(fn (string $state, Appointment $record, HasTable $livewire) => match (true) {
                $state === 'Alerta médica' => VisitFlags::of($record, auth()->user(), $livewire->getTableRecords())['alertSevere'] ? 'danger' : 'warning',
                $state === 'Llamar' => 'danger',
                $state === 'Nuevo' => 'info',
                $state === 'Pidió cambio', str_starts_with($state, 'Debe'), str_starts_with($state, 'Faltó') => 'warning',
                default => 'gray',
            })
            ->tooltip(fn (Appointment $record, HasTable $livewire) => VisitFlags::of($record, auth()->user(), $livewire->getTableRecords())['alert']);
    }
}
