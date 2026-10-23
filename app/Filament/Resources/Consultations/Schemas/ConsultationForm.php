<?php

namespace App\Filament\Resources\Consultations\Schemas;

use App\Enums\DiagnosisType;
use App\Enums\PlanItemStatus;
use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
use App\Enums\ToothState;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Actions\UploadClinicalFilesAction;
use App\Filament\Forms\QuickTextarea;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Support\ClinicalAlerts;
use App\Livewire\OdontogramChart;
use App\Models\Consultation;
use App\Models\Icd10Code;
use App\Models\OdontogramEntry;
use App\Models\Prescription;
use App\Models\TextSnippet;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Pantalla de la atención: todo lo que el doctor escribe mientras atiende, de arriba hacia abajo. */
class ConsultationForm
{
    /** Hallazgo en rojo del odontograma → diagnóstico CIE-10 que se sugiere. */
    public const DIAGNOSIS_FOR = [
        'caries' => 'K02.1',
        'remanente_radicular' => 'K08.3',
        'fractura' => 'S02.5',
        'impactacion' => 'K01.1',
        'superficie_desgastada' => 'K03.0',
        'defecto_esmalte' => 'K00.4',
        'posicion' => 'K07.3',
        'giroversion' => 'K07.3',
        'migracion' => 'K07.3',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Group::make(fn (?Consultation $record) => array_filter([ClinicalAlerts::callout($record?->patient)])),

                Section::make('Motivo y examen')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->columns(2)
                    ->schema([
                        QuickTextarea::make('chief_complaint')
                            ->label('Motivo de consulta')
                            ->snippets(SnippetField::ChiefComplaint),
                        QuickTextarea::make('current_illness')
                            ->label('Enfermedad actual')
                            ->snippets(SnippetField::Any),
                        QuickTextarea::make('clinical_exam')
                            ->label('Examen clínico')
                            ->snippets(SnippetField::ClinicalExam)
                            ->columnSpanFull(),
                        Grid::make(3)
                            ->columnSpanFull()
                            ->schema([
                                TextInput::make('vital_signs.blood_pressure')
                                    ->label('Presión arterial')
                                    ->placeholder('120/80')
                                    ->maxLength(10),
                                TextInput::make('vital_signs.heart_rate')
                                    ->label('Pulso')
                                    ->suffix('lpm')
                                    ->numeric()
                                    ->minValue(20)
                                    ->maxValue(250),
                                TextInput::make('vital_signs.temperature')
                                    ->label('Temperatura')
                                    ->suffix('°C')
                                    ->numeric()
                                    ->minValue(30)
                                    ->maxValue(45),
                            ]),
                    ]),

                Section::make('Odontograma')
                    ->icon(Heroicon::OutlinedSquares2x2)
                    ->description('Cada toque se guarda solo. Parte del odontograma de la atención anterior.')
                    ->schema([
                        Livewire::make(OdontogramChart::class, ['editable' => true])
                            ->key('odontogram'),
                    ]),

                Section::make('Diagnóstico')
                    ->key('diagnosis')
                    ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                    ->headerActions([self::suggestDiagnosesAction()])
                    ->schema([
                        Repeater::make('diagnoses')
                            ->hiddenLabel()
                            ->relationship()
                            ->addActionLabel('Agregar diagnóstico')
                            ->defaultItems(0)
                            ->columns(6)
                            ->itemLabel(fn (array $state) => filled($state['tooth'] ?? null) ? 'Pieza '.$state['tooth'] : null)
                            ->schema([
                                Select::make('icd10_code_id')
                                    ->label('CIE-10')
                                    ->options(fn () => Icd10Code::query()->orderBy('code')->get()->mapWithKeys(fn (Icd10Code $code) => [$code->getKey() => "{$code->code} · {$code->description}"]))
                                    ->searchable()
                                    ->required()
                                    ->columnSpan(3),
                                TextInput::make('tooth')
                                    ->label('Pieza')
                                    ->placeholder('36')
                                    ->maxLength(10)
                                    ->columnSpan(1),
                                ToggleButtons::make('type')
                                    ->label('Tipo')
                                    ->options(DiagnosisType::class)
                                    ->default(DiagnosisType::Definitive)
                                    ->inline()
                                    ->grouped()
                                    ->columnSpan(2),
                            ]),
                        QuickTextarea::make('diagnosis_notes')
                            ->label('Notas del diagnóstico')
                            ->snippets(SnippetField::Diagnosis),
                    ]),

                Section::make('Plan de tratamiento')
                    ->key('plan')
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->description('Marca lo que se trabajó hoy. Al terminar la atención, el avance del plan se actualiza solo.')
                    ->headerActions([self::draftPlanAction()])
                    ->schema([
                        CheckboxList::make('planItems')
                            ->label('Lo que se trabajó hoy')
                            ->hiddenLabel()
                            ->relationship('planItems', 'description', modifyQueryUsing: fn (Builder $query, ?Consultation $record) => $query
                                ->with('plan')
                                ->whereHas('plan', fn (Builder $plan) => $plan->open()->where('patient_id', $record?->patient_id))
                                ->where(fn (Builder $items) => $items
                                    ->whereIn('treatment_plan_items.status', [PlanItemStatus::Pending, PlanItemStatus::InProgress])
                                    ->when($record, fn (Builder $items) => $items->orWhereHas('consultations', fn (Builder $c) => $c->whereKey($record->getKey()))))
                                ->reorder()
                                ->orderBy('treatment_plan_items.treatment_plan_id')
                                ->orderBy('treatment_plan_items.sort_order'))
                            ->getOptionLabelFromRecordUsing(fn (TreatmentPlanItem $record) => $record->label())
                            ->getOptionDescriptionFromRecordUsing(fn (TreatmentPlanItem $record) => collect([
                                $record->sessions_planned > 1 ? "Sesiones hechas: {$record->sessions_done} de {$record->sessions_planned}" : null,
                                $record->plan->title,
                            ])->filter()->implode(' · '))
                            ->columns(2)
                            ->bulkToggleable(false)
                            ->visible(fn (?Consultation $record) => $record && self::workableItems($record)->isNotEmpty()),
                        Text::make('El paciente no tiene un plan de tratamiento vigente. Ármalo con un toque desde lo que está en rojo en el odontograma.')
                            ->color('gray')
                            ->visible(fn (?Consultation $record) => ! $record || self::workableItems($record)->isEmpty()),
                    ]),

                Section::make('Radiografías y fotos')
                    ->key('files')
                    ->icon(Heroicon::OutlinedPhoto)
                    ->collapsible()
                    ->headerActions([
                        UploadClinicalFilesAction::make(fn (Consultation $record) => $record->patient, fn (Consultation $record) => $record)
                            ->label('Subir')
                            ->link()
                            ->visible(fn (?Consultation $record) => $record && auth()->user()->can('update', $record)),
                    ])
                    ->schema([
                        View::make('filament.clinical.files-grid')
                            ->viewData(fn (?Consultation $record) => ['files' => $record?->files()->latest('id')->get() ?? collect()]),
                    ]),

                Section::make('Evolución')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->description('Qué se hizo hoy.')
                    ->schema([
                        QuickTextarea::make('procedures')
                            ->hiddenLabel()
                            ->label('Evolución')
                            ->snippets(SnippetField::Procedures),
                    ]),

                Section::make('Indicaciones y próximos pasos')
                    ->icon(Heroicon::OutlinedClipboardDocument)
                    ->columns(2)
                    ->schema([
                        QuickTextarea::make('indications')
                            ->label('Indicaciones para el paciente')
                            ->snippets(SnippetField::Indications),
                        QuickTextarea::make('next_steps')
                            ->label('Próximos pasos')
                            ->placeholder('Ej.: resina en 36 la próxima sesión')
                            ->snippets(SnippetField::Any),
                    ]),

                Section::make('Receta')
                    ->key('prescription')
                    ->icon(Heroicon::OutlinedBeaker)
                    ->description('Se imprime o se envía por WhatsApp junto con las indicaciones.')
                    ->headerActions([self::printPrescriptionAction(), self::sendPrescriptionAction()])
                    ->schema([
                        Repeater::make('prescription_items')
                            ->hiddenLabel()
                            ->addActionLabel('Agregar medicamento')
                            ->defaultItems(0)
                            ->columns(5)
                            ->extraItemActions([self::saveMedicationAction()])
                            ->schema([
                                TextInput::make('medication')
                                    ->label('Medicamento')
                                    ->placeholder('Ibuprofeno 400 mg')
                                    ->datalist(fn () => self::medicationSnippets()->map(fn (TextSnippet $snippet) => app(PrescriptionService::class)->fromSnippet($snippet)['medication'])->unique()->values()->all())
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                TextInput::make('instructions')
                                    ->label('Cómo tomarlo')
                                    ->placeholder('1 tableta cada 8 horas por 3 días')
                                    ->maxLength(255)
                                    ->columnSpan(3),
                            ]),
                        Actions::make(fn () => self::medicationSnippets()
                            ->map(fn (TextSnippet $snippet) => Action::make('addMedication'.$snippet->getKey())
                                ->label($snippet->label)
                                ->icon(Heroicon::OutlinedPlus)
                                ->size('sm')
                                ->color('gray')
                                ->outlined()
                                ->action(function (Get $get, Set $set) use ($snippet) {
                                    $set('prescription_items', PrescriptionService::merge($get('prescription_items') ?? [], [app(PrescriptionService::class)->fromSnippet($snippet)]));
                                    $snippet->recordUse(auth()->user());
                                }))
                            ->all()),
                    ]),
            ]);
    }

    /** Medicamentos frecuentes (compartidos y propios), los más usados primero. */
    private static function medicationSnippets(): Collection
    {
        /** @var User $user */
        $user = auth()->user();

        return TextSnippet::query()
            ->availableFor($user, SnippetField::Prescription)
            ->where('field', SnippetField::Prescription)
            ->limit(QuickTextarea::VISIBLE_OPTIONS)
            ->get();
    }

    /** @return Collection<int, TreatmentPlanItem> */
    private static function workableItems(Consultation $consultation): Collection
    {
        return app(TreatmentPlanService::class)->workableItems($consultation->patient, $consultation);
    }

    /** Arma (o completa) el borrador del plan con lo que está en rojo en el odontograma de hoy. */
    private static function draftPlanAction(): Action
    {
        return Action::make('draftPlan')
            ->label('Armar plan desde el odontograma')
            ->icon(Heroicon::OutlinedSparkles)
            ->link()
            ->visible(fn (?Consultation $record) => $record && auth()->user()->can('update', $record))
            ->action(function (Consultation $record) {
                [$plan, $added] = app(TreatmentPlanService::class)->draftFromConsultation($record, auth()->user());

                if (! $plan) {
                    Notification::make()->info()->title('No hay hallazgos nuevos en rojo para el plan.')->body('Lo que ya está en un plan vigente no se repite.')->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title("Se agregaron {$added} procedimientos al plan, por ".Money::format($plan->load('items')->total()).' en total.')
                    ->body('Quedó como borrador. Revísalo, imprímelo o envíalo por WhatsApp desde la historia clínica.')
                    ->actions([
                        Action::make('open')
                            ->label('Ver el plan')
                            ->url(PatientResource::getUrl('clinical', ['record' => $record->patient_id]))
                            ->openUrlInNewTab(),
                    ])
                    ->send();
            });
    }

    /** Guarda y abre la receta lista para imprimir (o guardar en PDF). */
    private static function printPrescriptionAction(): Action
    {
        return Action::make('printPrescription')
            ->label('Imprimir')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->link()
            ->action(function (Consultation $record, EditConsultation $livewire) {
                if (! $prescription = self::savedPrescription($record, $livewire)) {
                    return;
                }

                $livewire->js('window.open('.json_encode(ConsultationResource::prescriptionPrintUrl($prescription)).', "_blank")');
            });
    }

    private static function sendPrescriptionAction(): Action
    {
        return SendWhatsAppAction::compose('sendPrescription', function (Consultation $record, EditConsultation $livewire) {
            $prescription = self::savedPrescription($record, $livewire);

            return $prescription ? app(PrescriptionService::class)->whatsappDraft($prescription) : null;
        })
            ->label('WhatsApp')
            ->link()
            ->visible(fn (?Consultation $record) => $record?->patient->account_id !== null);
    }

    /** Guarda lo escrito y devuelve la receta; avisa si todavía no tiene medicamentos. */
    private static function savedPrescription(Consultation $record, EditConsultation $livewire): ?Prescription
    {
        $livewire->save(shouldRedirect: false, shouldSendSavedNotification: false);

        $prescription = $record->refresh()->prescription;

        if (! $prescription) {
            Notification::make()->warning()->title('Agrega al menos un medicamento a la receta.')->send();
        }

        return $prescription;
    }

    /** Guarda el medicamento del renglón como opción propia para la próxima vez. */
    private static function saveMedicationAction(): Action
    {
        return Action::make('saveMedication')
            ->icon(Heroicon::OutlinedBookmark)
            ->tooltip('Guardar como opción')
            ->color('gray')
            ->action(function (array $arguments, Repeater $component) {
                $item = $component->getRawItemState($arguments['item']);
                $medication = trim((string) ($item['medication'] ?? ''));

                if ($medication === '') {
                    Notification::make()->warning()->title('Escribe primero el medicamento.')->send();

                    return;
                }

                TextSnippet::updateOrCreate(
                    ['user_id' => auth()->id(), 'field' => SnippetField::Prescription, 'label' => $medication],
                    ['body' => collect([$medication, trim((string) ($item['instructions'] ?? ''))])->filter()->implode(': '), 'status' => SnippetStatus::Active],
                );

                Notification::make()->success()->title("Listo: {$medication} aparecerá entre tus medicamentos frecuentes.")->send();
            });
    }

    /** Agrega a la lista los hallazgos en rojo del odontograma de hoy que todavía no tienen diagnóstico. */
    private static function suggestDiagnosesAction(): Action
    {
        return Action::make('suggestDiagnoses')
            ->label('Sugerir desde el odontograma')
            ->icon(Heroicon::OutlinedSparkles)
            ->link()
            ->action(function (?Consultation $record, Get $get, Set $set) {
                $codes = Icd10Code::query()->whereIn('code', array_unique(self::DIAGNOSIS_FOR))->pluck('id', 'code');
                $current = collect($get('diagnoses') ?? []);
                $added = 0;

                $entries = $record?->odontogram?->entries()->with('condition')->where('state', ToothState::Bad)->orderBy('tooth')->get() ?? collect();

                foreach ($entries as $entry) {
                    /** @var OdontogramEntry $entry */
                    $codeId = $codes[self::DIAGNOSIS_FOR[$entry->condition->code] ?? ''] ?? null;
                    $exists = $current->contains(fn (array $row) => (int) ($row['icd10_code_id'] ?? 0) === $codeId && (string) ($row['tooth'] ?? '') === (string) $entry->tooth);

                    if (! $codeId || $exists) {
                        continue;
                    }

                    $current->put('suggested-'.$entry->getKey(), ['icd10_code_id' => $codeId, 'tooth' => (string) $entry->tooth, 'type' => DiagnosisType::Definitive->value]);
                    $added++;
                }

                $set('diagnoses', $current->all());

                Notification::make()
                    ->title($added ? "Se agregaron {$added} diagnósticos. Revísalos y guarda." : 'No hay hallazgos nuevos en rojo para diagnosticar.')
                    ->status($added ? 'success' : 'info')
                    ->send();
            });
    }
}
