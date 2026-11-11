<?php

namespace App\Filament\Resources\Consultations\Schemas;

use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\ClinicalAlerts;
use App\Filament\Support\VisitCloseOut;
use App\Livewire\OdontogramChart;
use App\Models\Consultation;
use App\Models\ConsultationDiagnosis;
use App\Models\PrescriptionItem;
use App\Models\Treatment;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\Clinical\PrescriptionService;
use App\Services\Inventory\InventoryService;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

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

                View::make('filament.consultations.close-out')
                    ->viewData(fn (Consultation $record) => ['closeOut' => VisitCloseOut::viewData($record, auth()->user())])
                    ->visible(fn (Consultation $record) => $record->closed_at?->isToday() ?? false)
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
                            ->state(fn (Consultation $record) => $record->vitalsSummary())
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
                        TextEntry::make('procedure_summary')
                            ->label('Datos del tratamiento')
                            ->state(fn (Consultation $record) => $record->procedureSummary())
                            ->hidden(fn ($state) => blank($state))
                            ->columnSpanFull(),
                        TextEntry::make('procedures')->label('Evolución (qué se hizo)')->hidden(fn ($state) => blank($state))->columnSpanFull(),
                        TextEntry::make('done')
                            ->label('Lo que se hizo')
                            ->state(fn (Consultation $record) => [
                                ...$record->treatments->map(fn (Treatment $treatment) => $treatment->name)->all(),
                                ...$record->planItems->map(fn (TreatmentPlanItem $item) => $item->label().' · del plan')->all(),
                            ])
                            ->listWithLineBreaks()
                            ->bulleted()
                            ->hidden(fn ($state) => blank($state))
                            ->columnSpanFull(),
                        TextEntry::make('supplies_used')
                            ->label('Materiales usados')
                            ->state(fn (Consultation $record) => app(InventoryService::class)->usedIn($record))
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

                Group::make([
                    Section::make('Datos')
                        ->schema([
                            TextEntry::make('patient.first_name')
                                ->label('Paciente')
                                ->state(fn (Consultation $record) => $record->patient->full_name)
                                ->url(fn (Consultation $record) => PatientResource::getUrl('clinical', ['record' => $record->patient]))
                                ->color('primary')
                                ->weight('bold'),
                            TextEntry::make('attended_at')->label('Fecha')->dateTime('l d/m/Y · H:i'),
                            TextEntry::make('doctor.name')->label('Doctor'),
                            TextEntry::make('appointment.treatment.name')->label('Programado en la cita')->placeholder('Sin cita'),
                            TextEntry::make('closed_at')
                                ->label('Estado')
                                ->state(fn (Consultation $record) => $record->closed_at ? 'Terminada el '.$record->closed_at->format('d/m/Y H:i') : 'Sin terminar')
                                ->badge()
                                ->color(fn (Consultation $record) => $record->closed_at ? 'success' : 'warning'),
                        ]),

                    Section::make('Plan de tratamiento')
                        ->description('Cómo va su plan hoy: cada procedimiento con su precio.')
                        ->schema([
                            View::make('filament.consultations.plans')
                                ->viewData(fn (Consultation $record) => [
                                    'plans' => self::plans($record),
                                    'url' => PatientResource::getUrl('clinical', ['record' => $record->patient_id, 'relation' => '1']),
                                ]),
                        ])
                        ->visible(fn (Consultation $record) => self::plans($record)->isNotEmpty()),
                ])
                    ->columnSpan(1),

                Section::make('Odontograma de esta atención')
                    ->columnSpanFull()
                    ->collapsible()
                    ->schema([
                        Livewire::make(OdontogramChart::class)->key('odontogram'),
                    ]),
            ]);
    }

    /**
     * Su plan como está hoy: los vigentes y el que se armó en esta atención, aunque ya se haya terminado.
     *
     * @return Collection<int, TreatmentPlan>
     */
    public static function plans(Consultation $consultation): Collection
    {
        return TreatmentPlan::query()
            ->where('patient_id', $consultation->patient_id)
            ->where(fn ($query) => $query->open()->orWhere('consultation_id', $consultation->getKey()))
            ->with('items')
            ->orderBy('id')
            ->get();
    }
}
