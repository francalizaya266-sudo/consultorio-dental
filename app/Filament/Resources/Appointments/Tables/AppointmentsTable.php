<?php

namespace App\Filament\Resources\Appointments\Tables;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\AppointmentActions;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use App\Support\Phone;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class AppointmentsTable
{
    public static function configure(Table $table): Table
    {
        /** @var User $user */
        $user = auth()->user();

        return $table
            ->columns(self::columns(showDoctor: $user->managesAllAgendas()))
            ->defaultSort('starts_at')
            ->filters([
                SelectFilter::make('doctor_id')
                    ->label('Doctor')
                    ->options(fn () => Doctor::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                    ->visible($user->managesAllAgendas()),
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(AppointmentStatus::class)
                    ->multiple(),
                Filter::make('new_patients')
                    ->label('Solo pacientes nuevos')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->where('is_new_patient', true)),
            ])
            ->recordActions(AppointmentActions::all())
            ->poll('30s')
            ->emptyStateHeading('No hay citas')
            ->emptyStateDescription('Usa "Nueva cita" para agendar una.');
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
                ->dateTime($timeOnly ? 'H:i' : 'D d/m H:i')
                ->description(fn (Appointment $record) => $record->ends_at->diffInMinutes($record->starts_at, true).' min')
                ->sortable(),
            TextColumn::make('patient.first_name')
                ->label('Paciente')
                ->formatStateUsing(fn (Appointment $record) => $record->patient->full_name)
                ->description(fn (Appointment $record) => $record->patient->account ? Phone::display($record->patient->account->phone) : null)
                ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn (Builder $p) => $p->search($search))),
            TextColumn::make('treatment.name')
                ->label('Tratamiento')
                ->wrap(),
            TextColumn::make('doctor.name')
                ->label('Doctor')
                ->visible($showDoctor),
            TextColumn::make('status')
                ->label('Estado')
                ->badge(),
            TextColumn::make('flags')
                ->label('')
                ->state(fn (Appointment $record) => array_values(array_filter([
                    $record->needsCall() ? 'Llamar' : null,
                    $record->is_new_patient ? 'Nuevo' : null,
                    $record->fixed_date ? 'Fecha fija' : null,
                ])))
                ->badge()
                ->color(fn (string $state) => match ($state) {
                    'Llamar' => 'danger',
                    'Nuevo' => 'info',
                    default => 'gray',
                }),
        ];
    }
}
