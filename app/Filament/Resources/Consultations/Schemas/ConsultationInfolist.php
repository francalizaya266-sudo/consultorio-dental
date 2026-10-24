<?php

namespace App\Filament\Resources\Consultations\Schemas;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\ClinicalAlerts;
use App\Livewire\OdontogramChart;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\PrescriptionItem;
use App\Models\TreatmentPlanItem;
use App\Services\Clinical\PrescriptionService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/** Atención en modo lectura (cerrada o de otro doctor). */
class ConsultationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Group::make(fn (Consultation $record) => array_filter([ClinicalAlerts::callout($record->patient)]))
                    ->columnSpanFull(),

                Section::make('Atención')
                    ->columnSpan(2)
                    ->columns(2)
                    ->schema([
                        TextEntry::make('chief_complaint')->label('Motivo de consulta')->hidden(fn ($state) => blank($state)),
                        TextEntry::make('current_illness')->label('Enfermedad actual')->hidden(fn ($state) => blank($state)),
                        TextEntry::make('clinical_exam')->label('Examen clínico')->hidden(fn ($state) => blank($state))->columnSpanFull(),
                        TextEntry::make('vitals')
                            ->label('Signos vitales')
                            ->state(fn (Consultation $record) => collect([
                                filled($record->vital_signs['blood_pressure'] ?? null) ? 'PA '.$record->vital_signs['blood_pressure'] : null,
                                filled($record->vital_signs['heart_rate'] ?? null) ? 'Pulso '.$record->vital_signs['heart_rate'].' lpm' : null,
                                filled($record->vital_signs['temperature'] ?? null) ? $record->vital_signs['temperature'].' °C' : null,
                            ])->filter()->implode(' · ') ?: null)
                            ->hidden(fn ($state) => blank($state))
                            ->columnSpanFull(),
                        RepeatableEntry::make('diagnoses')
                            ->label('Diagnóstico')
                            ->contained(false)
                            ->columnSpanFull()
                            ->placeholder('Sin diagnósticos')
                            ->schema([
                                TextEntry::make('icd10Code.code')
                                    ->hiddenLabel()
                                    ->formatStateUsing(fn (ConsultationDiagnosis $record) => collect([
                                        $record->icd10Code ? "{$record->icd10Code->code} · {$record->icd10Code->description}" : $record->description,
                                        $record->tooth ? "pieza {$record->tooth}" : null,
                                        $record->type?->getLabel(),
                                    ])->filter()->implode(' · ')),
                            ]),
                        TextEntry::make('diagnosis_notes')->label('Notas del diagnóstico')->hidden(fn ($state) => blank($state))->columnSpanFull(),
                        TextEntry::make('procedures')->label('Evolución (qué se hizo)')->hidden(fn ($state) => blank($state))->columnSpanFull(),
                        TextEntry::make('planItems')
                            ->label('Del plan de tratamiento se trabajó')
                            ->state(fn (Consultation $record) => $record->planItems->map(fn (TreatmentPlanItem $item) => $item->label())->all())
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->hidden(fn ($state) => blank($state))
                            ->columnSpanFull(),
                        TextEntry::make('indications')->label('Indicaciones')->hidden(fn ($state) => blank($state)),
                        TextEntry::make('next_steps')->label('Próximos pasos')->hidden(fn ($state) => blank($state)),
                        TextEntry::make('prescription')
                            ->label('Receta')
                            ->state(fn (Consultation $record) => $record->prescription?->items->map(fn (PrescriptionItem $item) => $item->medication.(($instructions = app(PrescriptionService::class)->instructionsOf($item)) ? ': '.$instructions : ''))->all() ?? [])
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->placeholder('Sin receta')
                            ->columnSpanFull(),
                    ]),

                Section::make('Datos')
                    ->columnSpan(1)
                    ->schema([
                        TextEntry::make('patient.first_name')
                            ->label('Paciente')
                            ->state(fn (Consultation $record) => $record->patient->full_name)
                            ->url(fn (Consultation $record) => PatientResource::getUrl('clinical', ['record' => $record->patient]))
                            ->color('primary')
                            ->weight('bold'),
                        TextEntry::make('attended_at')->label('Fecha')->dateTime('l d/m/Y · H:i'),
                        TextEntry::make('doctor.name')->label('Doctor'),
                        TextEntry::make('appointment.treatment.name')->label('Tratamiento de la cita')->placeholder('Sin cita'),
                        TextEntry::make('closed_at')
                            ->label('Estado')
                            ->state(fn (Consultation $record) => $record->closed_at ? 'Terminada el '.$record->closed_at->format('d/m/Y H:i') : 'Sin terminar')
                            ->badge()
                            ->color(fn (Consultation $record) => $record->closed_at ? 'success' : 'warning'),
                    ]),

                Section::make('Odontograma de esta atención')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Livewire::make(OdontogramChart::class)->key('odontogram'),
                    ]),
            ]);
    }
}
