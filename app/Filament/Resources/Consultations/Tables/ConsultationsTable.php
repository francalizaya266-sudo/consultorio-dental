<?php

namespace App\Filament\Resources\Consultations\Tables;

use App\Filament\Resources\Consultations\ConsultationResource;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\Doctor;
use App\Models\User;
use Carbon\CarbonInterface;
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
            ->stackedOnMobile()
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
                // En la lista de Atenciones esto es una pestaña, con su número; en la historia de un paciente no hay pestañas.
                Filter::make('open')
                    ->label('Sin terminar')
                    ->toggle()
                    ->visible(! $showPatient)
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

    /** Se abrió otro día y nadie la terminó: mientras siga abierta no se cobra ni avanza el plan. */
    private static function leftOpen(Consultation $consultation): bool
    {
        return $consultation->closed_at === null && $consultation->attended_at->isBefore(today());
    }

    /**
     * Quién atendió, solo cuando hace falta decirlo: hay varios doctores y no fue quien mira. Antes era una
     * columna que repetía el mismo nombre en cada fila y dejaba el estado fuera de la pantalla.
     */
    private static function attendedBy(Consultation $consultation): ?string
    {
        return Doctor::severalActive() && $consultation->doctor_id !== auth()->user()->doctorId()
            ? $consultation->doctor?->name
            : null;
    }

    /**
     * Fecha, paciente, motivo, diagnóstico y estado: entran en una laptop sin tener que deslizar. El doctor va
     * bajo el paciente (o bajo la fecha, en la historia de un paciente, que no lleva esa columna).
     *
     * @return list<TextColumn>
     */
    public static function columns(bool $showPatient = true): array
    {
        return array_values(array_filter([
            TextColumn::make('attended_at')
                ->label('Fecha')
                ->dateTime('d/m/Y H:i')
                ->description(fn (Consultation $record) => $showPatient ? null : self::attendedBy($record))
                ->sortable(),
            $showPatient ? TextColumn::make('patient.first_name')
                ->label('Paciente')
                ->formatStateUsing(fn (Consultation $record) => $record->patient->full_name)
                ->description(fn (Consultation $record) => self::attendedBy($record))
                ->weight('bold')
                ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('patient', fn (Builder $patient) => $patient->search($search)))
                ->extraCellAttributes(['style' => 'min-width: 11rem'])
                ->wrap() : null,
            TextColumn::make('chief_complaint')
                ->label('Motivo')
                ->limit(90)
                ->placeholder('—')
                ->extraCellAttributes(['style' => 'min-width: 14rem'])
                ->wrap(),
            TextColumn::make('diagnoses')
                ->label('Diagnóstico')
                ->state(fn (Consultation $record) => $record->diagnoses->map(fn (ConsultationDiagnosis $d) => trim(($d->icd10Code?->code ?? '').($d->tooth ? " ({$d->tooth})" : '')))->filter()->all())
                ->badge()
                ->color('gray')
                // Con muchos diagnósticos se ven los dos primeros y cuántos más hay: el detalle está al abrirla.
                ->limitList(2),
            TextColumn::make('closed_at')
                ->label('Estado')
                ->state(fn (Consultation $record) => $record->closed_at ? 'Terminada' : 'Sin terminar')
                ->badge()
                ->color(fn (Consultation $record) => match (true) {
                    $record->closed_at !== null => 'success',
                    self::leftOpen($record) => 'danger',
                    default => 'warning',
                })
                ->description(fn (Consultation $record) => self::leftOpen($record)
                    ? 'Quedó abierta '.$record->attended_at->copy()->startOfDay()->diffForHumans(today(), ['syntax' => CarbonInterface::DIFF_RELATIVE_TO_NOW, 'parts' => 1]).': termínala o descártala'
                    : null)
                ->extraCellAttributes(['style' => 'min-width: 10rem'])
                ->wrap(),
        ]));
    }
}
