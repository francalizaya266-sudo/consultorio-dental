<?php

namespace App\Filament\Resources\Consultations\Tables;

use App\Filament\Resources\Consultations\ConsultationResource;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ConsultationsTable
{
    public static function configure(Table $table, bool $showPatient = true): Table
    {
        /** @var User $user */
        $user = auth()->user();

        return $table
            ->columns(self::columns($showPatient))
            ->defaultSort('attended_at', 'desc')
            ->recordUrl(fn (Consultation $record) => ConsultationResource::urlFor($record))
            ->filters([
                Filter::make('mine')
                    ->label('Solo mis atenciones')
                    ->toggle()
                    ->default($user->doctorId() !== null)
                    ->visible($user->doctorId() !== null)
                    ->query(fn (Builder $query) => $query->where('doctor_id', $user->doctorId())),
                Filter::make('open')
                    ->label('Sin terminar')
                    ->toggle()
                    ->query(fn (Builder $query) => $query->whereNull('closed_at')),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(fn (Consultation $record) => $user->can('update', $record) ? 'Continuar' : 'Ver')
                    ->icon(fn (Consultation $record) => $user->can('update', $record) ? Heroicon::OutlinedPencilSquare : Heroicon::OutlinedEye)
                    ->url(fn (Consultation $record) => ConsultationResource::urlFor($record)),
            ])
            ->emptyStateHeading('Todavía no hay atenciones')
            ->emptyStateDescription('Se crean al tocar "Atender" en una cita.');
    }

    /** @return list<TextColumn> */
    public static function columns(bool $showPatient = true): array
    {
        return array_values(array_filter([
            TextColumn::make('attended_at')
                ->label('Fecha')
                ->dateTime('d/m/Y H:i')
                ->sortable(),
            $showPatient ? TextColumn::make('patient.first_name')
                ->label('Paciente')
                ->formatStateUsing(fn (Consultation $record) => $record->patient->full_name)
                ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn (Builder $patient) => $patient->search($search))) : null,
            TextColumn::make('doctor.name')
                ->label('Doctor'),
            TextColumn::make('chief_complaint')
                ->label('Motivo')
                ->limit(50)
                ->placeholder('—')
                ->wrap(),
            TextColumn::make('diagnoses')
                ->label('Diagnóstico')
                ->state(fn (Consultation $record) => $record->diagnoses->map(fn (ConsultationDiagnosis $d) => trim(($d->icd10Code?->code ?? '').($d->tooth ? " ({$d->tooth})" : '')))->filter()->all())
                ->badge()
                ->color('gray'),
            TextColumn::make('closed_at')
                ->label('Estado')
                ->state(fn (Consultation $record) => $record->closed_at ? 'Terminada' : 'Sin terminar')
                ->badge()
                ->color(fn (Consultation $record) => $record->closed_at ? 'success' : 'warning'),
        ]));
    }
}
