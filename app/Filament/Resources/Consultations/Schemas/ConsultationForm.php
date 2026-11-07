<?php

namespace App\Filament\Resources\Consultations\Schemas;

use App\Enums\DiagnosisType;
use App\Enums\PlanItemStatus;
use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
use App\Enums\TreatmentKind;
use App\Enums\VisitType;
use App\Exceptions\ClinicalException;
use App\Filament\Actions\SendWhatsAppAction;
use App\Filament\Actions\UploadClinicalFilesAction;
use App\Filament\Forms\QuickTextarea;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Pages\EditConsultation;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\Patients\Schemas\TreatmentPlanForm;
use App\Filament\Support\ClinicalAlerts;
use App\Livewire\OdontogramChart;
use App\Models\Campaign;
use App\Models\Consent;
use App\Models\ConsentTemplate;
use App\Models\Consultation;
use App\Models\Icd10Code;
use App\Models\MedicalBackground;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\TextSnippet;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlanItem;
use App\Models\User;
use App\Services\Billing\BillingService;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\MedicationCheck;
use App\Services\Clinical\PediatricDose;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\Clinical\TreatmentRisks;
use App\Support\CampaignText;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Components\ToggleButtons;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Flex;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/**
 * Pantalla de la atención: todo lo que el doctor escribe mientras atiende, en el orden en que pasa la cita.
 * Arriba, una barra fija con el paciente, sus alertas, las secciones y Terminar. Después, tres pasos: antes de
 * empezar (motivo, examen, radiografías), lo que hiciste (con el odontograma fijo a la izquierda en la tablet
 * y la computadora) y lo que se lleva el paciente (indicaciones y receta, que salen juntas).
 */
class ConsultationForm
{
    /** @var array<string, string> Secciones de la barra fija: ancla → nombre. */
    public const SECTIONS = [
        'motivo' => 'Motivo',
        'archivos' => 'Rx y fotos',
        'odontograma' => 'Odontograma',
        'diagnostico' => 'Diagnóstico',
        'plan' => 'Tratamientos',
        'evolucion' => 'Evolución',
        'indicaciones' => 'Para el paciente',
    ];

    /** Aviso al imprimir o enviar la receta de un paciente sin antecedentes. */
    private const WITHOUT_BACKGROUND = 'No sabemos si tiene alergias: no hay antecedentes registrados. Pregúntale antes de entregarle la receta.';

    /** Enfermedades con las que conviene tomar la presión y el pulso antes de empezar. */
    private const VITALS_CONDITIONS = ['hypertension', 'heart_disease', 'diabetes', 'bleeding_disorder', 'kidney_disease', 'thyroid'];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.clinical.consultation-bar')
                    ->viewData(fn (?Consultation $record, $livewire) => self::barData($record, $livewire)),

                self::step(1, 'Antes de empezar', fn (?Consultation $record) => match ($record?->visitType()) {
                    VisitType::PlanSession => 'Es una sesión de su plan: revisa sus antecedentes y anota solo lo que haya de nuevo.',
                    VisitType::Control => 'Es un control: pregúntale cómo le ha ido y anota solo si hay algo nuevo.',
                    VisitType::Urgent => 'Sin cita o de urgencia: qué le pasa, desde cuándo, cuánto le duele y sus signos vitales.',
                    default => 'Lo que cuenta el paciente y lo que encuentras al examinarlo.',
                }),

                Callout::make('Faltan los antecedentes médicos')
                    ->key('backgroundMissing')
                    ->description('Pregunta por alergias, enfermedades y medicamentos antes de empezar. Se registra aquí mismo, sin salir de la atención.')
                    ->warning()
                    ->actions([self::askBackgroundAction()])
                    ->visible(fn (?Consultation $record) => $record && ! $record->patient->medicalBackground),

                Callout::make('Revisa antes de empezar')
                    ->key('risks')
                    ->description(fn (?Consultation $record) => self::bulletList($record ? self::risks($record) : []))
                    ->icon(Heroicon::OutlinedShieldExclamation)
                    ->warning()
                    ->visible(fn (?Consultation $record) => $record && self::risks($record) !== []),

                Section::make('Motivo y examen')
                    ->id('motivo')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->description(fn (?Consultation $record) => self::reasonHint($record))
                    ->collapsible(fn (?Consultation $record) => (bool) $record?->visitType()->repeatsAnotherVisit())
                    ->collapsed(fn (?Consultation $record) => self::foldsReason($record), shouldMakeComponentCollapsible: false)
                    ->columns(['default' => 1, 'lg' => 2])
                    ->schema([
                        Callout::make('Al reservar escribió')
                            ->key('patientNote')
                            ->description(fn (?Consultation $record) => '«'.trim((string) $record?->appointment?->patient_note).'»')
                            ->icon(Heroicon::OutlinedChatBubbleBottomCenterText)
                            ->info()
                            ->actions([
                                Action::make('usePatientNote')
                                    ->label('Usar como motivo')
                                    ->link()
                                    ->action(fn (Get $get, Set $set, Consultation $record) => $set('chief_complaint', collect([trim((string) $get('chief_complaint')), trim((string) $record->appointment?->patient_note)])->filter()->implode("\n"))),
                            ])
                            ->visible(fn (Get $get, ?Consultation $record) => filled($note = trim((string) $record?->appointment?->patient_note)) && ! str_contains((string) $get('chief_complaint'), $note))
                            ->columnSpanFull(),
                        QuickTextarea::make('chief_complaint')
                            ->label('Motivo de consulta')
                            ->snippets(SnippetField::ChiefComplaint),
                        QuickTextarea::make('clinical_exam')
                            ->label('Examen clínico')
                            ->snippets(SnippetField::ClinicalExam),
                        Section::make('Enfermedad actual y signos vitales')
                            ->key('vitals')
                            ->description(fn (?Consultation $record) => self::vitalsHint($record))
                            ->compact()
                            ->secondary()
                            ->collapsed(fn (?Consultation $record) => ! self::opensVitals($record))
                            ->columnSpanFull()
                            ->schema([
                                QuickTextarea::make('current_illness')
                                    ->label('Enfermedad actual')
                                    ->snippets(SnippetField::Any),
                                Grid::make(['default' => 2, 'sm' => 4])
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
                                        TextInput::make('vital_signs.pain')
                                            ->label('Dolor')
                                            ->helperText('De 0 (nada) a 10')
                                            ->suffix('/10')
                                            ->integer()
                                            ->minValue(0)
                                            ->maxValue(10),
                                    ]),
                            ]),
                    ]),

                Section::make('Radiografías y fotos')
                    ->id('archivos')
                    ->key('files')
                    ->icon(Heroicon::OutlinedPhoto)
                    ->compact()
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

                self::step(2, 'Lo que hiciste', fn (?Consultation $record) => self::hidesOdontogram($record)
                    ? 'Diagnóstico, lo que se cobra y cómo fue.'
                    : 'Odontograma, diagnóstico, lo que se cobra y cómo fue.'),

                Grid::make(['default' => 1, 'lg' => 5])
                    ->extraAttributes(fn (?Consultation $record) => ['class' => self::hidesOdontogram($record) ? 'consultation-split is-single' : 'consultation-split'])
                    ->schema([
                        Group::make([
                            Section::make('Odontograma')
                                ->id('odontograma')
                                ->icon(Heroicon::OutlinedSquares2x2)
                                ->description('Cada toque se guarda solo. Parte del odontograma de la atención anterior.')
                                ->schema([
                                    Livewire::make(OdontogramChart::class, ['editable' => true])
                                        ->key('odontogram'),
                                ]),
                        ])
                            ->columnSpan(['lg' => 3])
                            ->hidden(fn (?Consultation $record) => self::hidesOdontogram($record)),
                        Group::make([
                            Section::make('Diagnóstico')
                                ->id('diagnostico')
                                ->key('diagnosis')
                                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                                ->description(fn (?Consultation $record) => self::diagnosisHint($record))
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
                                                ->columnSpanFull(),
                                            TextInput::make('tooth')
                                                ->label('Pieza')
                                                ->placeholder('36')
                                                ->maxLength(10)
                                                ->columnSpan(2),
                                            ToggleButtons::make('type')
                                                ->label('Tipo')
                                                ->options(DiagnosisType::class)
                                                ->default(DiagnosisType::Definitive)
                                                ->inline()
                                                ->grouped()
                                                ->columnSpan(4),
                                        ]),
                                    QuickTextarea::make('diagnosis_notes')
                                        ->label('Notas del diagnóstico')
                                        ->snippets(SnippetField::Diagnosis)
                                        ->visible(fn (Get $get, $livewire) => filled($get('diagnosis_notes')) || ($livewire->showDiagnosisNotes ?? true)),
                                    Actions::make([
                                        Action::make('addDiagnosisNotes')
                                            ->label('Agregar una nota')
                                            ->icon(Heroicon::OutlinedPlus)
                                            ->link()
                                            ->action(fn ($livewire) => $livewire->showDiagnosisNotes = true),
                                    ])->visible(fn (Get $get, $livewire) => blank($get('diagnosis_notes')) && ! ($livewire->showDiagnosisNotes ?? true)),
                                ]),

                            Section::make('Tratamientos y cobro')
                                ->id('plan')
                                ->key('plan')
                                ->icon(Heroicon::OutlinedClipboardDocumentList)
                                ->description('Llega con lo programado. Deja marcado solo lo que se hizo: eso se cobra, sale del inventario y avanza el plan.')
                                ->headerActions([self::addPackageAction()])
                                ->schema([
                                    Select::make('treatments')
                                        ->label('Tratamientos')
                                        ->relationship('treatments', 'name', modifyQueryUsing: fn (Builder $query, ?Consultation $record) => $query
                                            ->where(fn (Builder $treatments) => $treatments
                                                ->where('treatments.is_active', true)
                                                ->when($record, fn (Builder $treatments) => $treatments->orWhereHas('consultations', fn (Builder $c) => $c->whereKey($record->getKey()))))
                                            ->reorder()
                                            ->orderBy('treatments.sort_order')
                                            ->orderBy('treatments.name'))
                                        ->getOptionLabelFromRecordUsing(fn (Treatment $record) => $record->name.((float) $record->price_from > 0 ? ' · '.str_replace(' ', "\u{00A0}", Money::format($record->price_from)) : ''))
                                        ->multiple()
                                        ->preload()
                                        ->searchable()
                                        ->placeholder('Agrega lo que se hizo fuera del plan')
                                        ->live()
                                        ->afterStateUpdated(fn (Get $get, Set $set, ?Consultation $record) => $record ? $set('prices', self::pricesFor($record, $get('treatments'), $get('planItems'), $get('prices'))) : null),
                                    CheckboxList::make('planItems')
                                        ->label('Del plan de tratamiento')
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
                                        ->bulkToggleable(false)
                                        ->live()
                                        ->afterStateUpdated(function (?array $state, ?array $old, Get $get, Set $set, ?Consultation $record): void {
                                            $set('treatments', $treatments = self::withoutWhatThePlanCovers($get('treatments'), array_diff($state ?? [], $old ?? [])));

                                            if ($record) {
                                                $set('prices', self::pricesFor($record, $treatments, $state, $get('prices')));
                                            }
                                        })
                                        ->visible(fn (?Consultation $record) => $record && self::workableItems($record)->isNotEmpty()),
                                    Text::make('No tiene un plan de tratamiento vigente. Si hace falta, ármalo desde las sugerencias que salen debajo del odontograma.')
                                        ->color('gray')
                                        ->visible(fn (?Consultation $record) => ! $record || (self::workableItems($record)->isEmpty() && ! self::hidesOdontogram($record))),
                                    Group::make(fn (Get $get, ?Consultation $record) => $record ? self::chargeLines($record, $get('treatments'), $get('planItems'), $get('prices')) : [])
                                        ->extraAttributes(['class' => 'consult-charges']),
                                ]),

                            Section::make('Nota de evolución')
                                ->id('evolucion')
                                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                                ->description('Cómo fue: anestesia, técnica, materiales e incidencias.')
                                ->schema([
                                    Grid::make(['default' => 2, 'sm' => 3])
                                        ->key('procedureDetails')
                                        ->schema(fn (?Consultation $record) => self::procedureDetailFields($record))
                                        ->visible(fn (?Consultation $record) => $record && $record->procedureKind()->detailFields() !== []),
                                    QuickTextarea::make('procedures')
                                        ->hiddenLabel()
                                        ->label('Nota de evolución')
                                        ->snippets(SnippetField::Procedures),
                                    QuickTextarea::make('next_steps')
                                        ->label('Próximos pasos')
                                        ->placeholder('Ej.: resina en 36 la próxima sesión')
                                        ->snippets(SnippetField::Any),
                                ]),
                        ])->columnSpan(fn (?Consultation $record) => self::hidesOdontogram($record) ? 'full' : ['lg' => 2]),
                    ]),

                self::step(3, 'Para el paciente', 'Lo que se lleva a casa. Las indicaciones y la receta se imprimen o se envían juntas.'),

                Section::make('Indicaciones y receta')
                    ->id('indicaciones')
                    ->key('prescription')
                    ->icon(Heroicon::OutlinedBeaker)
                    ->headerActions([self::printPrescriptionAction(), self::sendPrescriptionAction()])
                    ->schema([
                        QuickTextarea::make('indications')
                            ->label('Indicaciones')
                            ->snippets(SnippetField::Indications),
                        Callout::make('No sabemos si tiene alergias')
                            ->key('prescriptionWithoutBackground')
                            ->description('Pregúntale antes de recetar y regístralo: así la receta avisa si un medicamento choca con una alergia.')
                            ->danger()
                            ->actions([self::askBackgroundAction()])
                            ->visible(fn (Get $get, ?Consultation $record) => $record && ! $record->patient->medicalBackground && self::hasMedications($get('prescription_items'))),
                        Group::make([
                            Callout::make(fn (?Consultation $record) => 'Tiene '.$record?->patient->age.' años: la dosis va por su peso')
                                ->key('childDose')
                                ->description('Anota su peso y elige el jarabe: la dosis se calcula sola y la puedes corregir. Las recetas de adulto de las plantillas no se copian.')
                                ->icon(Heroicon::OutlinedScale)
                                ->info(),
                            Flex::make([
                                TextInput::make('vital_signs.weight')
                                    ->label('Peso')
                                    ->suffix('kg')
                                    ->numeric()
                                    ->minValue(2)
                                    ->maxValue(80)
                                    ->live(onBlur: true)
                                    ->grow(false),
                                Actions::make(self::childMedicationActions())
                                    ->key('childMedications'),
                            ])->from('sm')->verticallyAlignEnd(),
                            Callout::make('Falta su peso')
                                ->key('childWithoutWeight')
                                ->description('Sin el peso no se puede revisar la dosis de la receta.')
                                ->danger()
                                ->visible(fn (Get $get) => self::hasMedications($get('prescription_items')) && blank($get('vital_signs.weight'))),
                        ])
                            ->key('child')
                            ->visible(fn (?Consultation $record) => (bool) $record?->patient->isChild()),
                        Repeater::make('prescription_items')
                            ->label('Receta')
                            ->addActionLabel('Agregar medicamento')
                            ->defaultItems(0)
                            ->reorderable(false)
                            ->columns(5)
                            ->extraItemActions([self::saveMedicationAction()])
                            ->extraAttributes(['class' => 'consult-rx'])
                            ->schema([
                                TextInput::make('medication')
                                    ->label('Medicamento')
                                    ->placeholder('Ibuprofeno 400 mg')
                                    ->datalist(fn () => self::medicationSnippets()->map(fn (TextSnippet $snippet) => app(PrescriptionService::class)->fromSnippet($snippet)['medication'])->unique()->values()->all())
                                    ->maxLength(255)
                                    ->live(onBlur: true)
                                    ->columnSpan(2),
                                TextInput::make('instructions')
                                    ->label('Cómo tomarlo')
                                    ->placeholder('1 tableta cada 8 horas por 3 días')
                                    ->maxLength(255)
                                    ->columnSpan(3),
                                // Avisa si el medicamento choca con una alergia, la edad, el embarazo o una enfermedad.
                                Text::make(fn (Get $get, EditConsultation $livewire) => '⚠ '.implode(' ', self::medicationWarnings($livewire->getRecord()->patient, $get('medication'))))
                                    ->color('danger')
                                    ->weight(FontWeight::Bold)
                                    ->visible(fn (Get $get, EditConsultation $livewire) => self::medicationWarnings($livewire->getRecord()->patient, $get('medication')) !== [])
                                    ->columnSpanFull(),
                                // Un cuidado que no impide recetarlo, como un antiinflamatorio en un hipertenso.
                                Text::make(fn (Get $get, EditConsultation $livewire) => implode(' ', MedicationCheck::cautions($livewire->getRecord()->patient, $get('medication'))))
                                    ->color('warning')
                                    ->visible(fn (Get $get, EditConsultation $livewire) => MedicationCheck::cautions($livewire->getRecord()->patient, $get('medication')) !== [])
                                    ->columnSpanFull(),
                            ]),
                        Actions::make(fn (?Consultation $record) => self::medicationSnippets()
                            ->map(fn (TextSnippet $snippet) => Action::make('addMedication'.$snippet->getKey())
                                ->label($snippet->label)
                                ->icon(fn () => self::snippetWarnings($record, $snippet) === [] ? Heroicon::OutlinedPlus : Heroicon::OutlinedExclamationTriangle)
                                ->size('sm')
                                ->color(fn () => self::snippetWarnings($record, $snippet) === [] ? 'gray' : 'danger')
                                ->outlined()
                                // Antes de agregarlo, no después: si no le conviene, el doctor lo confirma.
                                ->requiresConfirmation(fn () => self::snippetWarnings($record, $snippet) !== [])
                                ->modalHeading(fn () => "¿Agregar {$snippet->label} igual?")
                                ->modalDescription(fn () => implode(' ', self::snippetWarnings($record, $snippet)))
                                ->modalSubmitActionLabel('Agregar igual')
                                ->modalCancelActionLabel('No agregar')
                                ->action(function (Get $get, Set $set, EditConsultation $livewire) use ($snippet) {
                                    $item = app(PrescriptionService::class)->fromSnippet($snippet);
                                    $set('prescription_items', PrescriptionService::merge($get('prescription_items') ?? [], [$item]));
                                    $snippet->recordUse(auth()->user());
                                    self::notifyWarnings($livewire->getRecord()->patient, $item['medication']);
                                }))
                            ->all())
                            ->visible(fn (?Consultation $record) => ! $record?->patient->isChild()),
                    ]),
            ]);
    }

    /**
     * Separador de cada paso de la atención: número, nombre y para qué sirve.
     *
     * @param  string|Closure(?Consultation): string  $description
     */
    private static function step(int $number, string $title, string|Closure $description): View
    {
        return View::make('filament.clinical.consultation-step')
            ->key('step'.$number)
            ->viewData(fn (?Consultation $record) => ['number' => $number, 'title' => $title, 'description' => $description instanceof Closure ? $description($record) : $description]);
    }

    /**
     * Motivo y examen se pliega en una sesión del plan: ya están en la atención donde se diagnosticó. Se queda
     * abierto si el paciente escribió algo al reservar, si ya se anotó algo más que el motivo automático o si
     * hay que tomarle los signos vitales (van dentro).
     */
    public static function foldsReason(?Consultation $record): bool
    {
        if (! $record || ! $record->visitType()->repeatsAnotherVisit()) {
            return false;
        }

        $note = trim((string) $record->appointment?->patient_note);

        return blank($record->clinical_exam)
            && ! self::opensVitals($record)
            && ($note === '' || str_contains((string) $record->chief_complaint, $note))
            && ConsultationService::isAutomaticReason($record, $record->chief_complaint);
    }

    /** Qué viene a hacer: de su plan (y desde cuándo), un control, una urgencia o, si es primera vez, por qué hay que anotarlo. */
    private static function reasonHint(?Consultation $record): ?string
    {
        $type = $record?->visitType();

        if ($type === VisitType::Control) {
            return 'Cita de control: anota solo si cuenta alguna molestia o algo nuevo.';
        }

        if ($type === VisitType::Urgent) {
            return 'Anota qué le duele o qué le pasa y desde cuándo. Sin motivo no se puede terminar la atención.';
        }

        $planned = $record?->plannedWork();

        if ($type === VisitType::PlanSession && $planned?->isNotEmpty()) {
            $since = $planned->map(fn (TreatmentPlanItem $item) => $item->plan->consultation?->attended_at ?? $item->plan->accepted_at)->filter()->min();

            return 'Sesión de su plan'.($since ? ' del '.$since->format('d/m') : '').': '
                .$planned->map(fn (TreatmentPlanItem $item) => $item->label())->implode(', ')
                .'. Anota aquí solo si cuenta algo nuevo.';
        }

        return $type === VisitType::FirstVisit
            ? 'No viene de un plan: lo que siente y lo que encuentras es lo que respalda en su historia el tratamiento de hoy.'
            : null;
    }

    /**
     * Lo que hay que revisar hoy por sus antecedentes y el tratamiento (anticoagulantes en una extracción,
     * embarazo en un blanqueamiento). En un control no se trabaja, así que no hay nada que revisar.
     *
     * @return list<string>
     */
    public static function risks(Consultation $record): array
    {
        return once(fn () => TreatmentRisks::for($record->patient, $record->procedureKind(), self::consentStatus($record) !== null));
    }

    /**
     * En un control de ortodoncia o en un blanqueamiento el odontograma no cambia: no se muestra y la columna
     * de la derecha toma todo el ancho.
     */
    public static function hidesOdontogram(?Consultation $record): bool
    {
        if (! $record) {
            return false;
        }

        $kind = TreatmentKind::for($record->mainTreatment());

        return $kind === TreatmentKind::Whitening
            || ($kind === TreatmentKind::Orthodontics && $record->visitType() === VisitType::Control);
    }

    /**
     * Avisos de un medicamento de la receta: alergias, edad, embarazo, enfermedades y lo que ya toma.
     *
     * @return list<string>
     */
    public static function medicationWarnings(?Patient $patient, ?string $medication): array
    {
        return PrescriptionService::warningsFor($patient, $medication);
    }

    /**
     * Lo que impide darle al paciente el medicamento de un botón rápido.
     *
     * @return list<string>
     */
    private static function snippetWarnings(?Consultation $record, TextSnippet $snippet): array
    {
        return self::medicationWarnings($record?->patient, app(PrescriptionService::class)->fromSnippet($snippet)['medication']);
    }

    private static function notifyWarnings(?Patient $patient, ?string $medication): void
    {
        foreach (self::medicationWarnings($patient, $medication) as $warning) {
            Notification::make()->danger()->title($warning)->persistent()->send();
        }
    }

    /**
     * Jarabes para niños, con la dosis calculada por su peso.
     *
     * @return list<Action>
     */
    private static function childMedicationActions(): array
    {
        return collect(PediatricDose::MEDICATIONS)
            ->map(fn (array $drug, string $key) => Action::make('addChildMedication'.Str::studly($key))
                ->label($drug['label'])
                ->icon(Heroicon::OutlinedPlus)
                ->size('sm')
                ->color('gray')
                ->outlined()
                ->action(function (Get $get, Set $set, EditConsultation $livewire) use ($key) {
                    $weight = (float) $get('vital_signs.weight');

                    if ($weight < 2 || $weight > 80) {
                        Notification::make()->warning()->title('Primero anota su peso: la dosis se calcula con él.')->send();

                        return;
                    }

                    $item = PediatricDose::item($key, $weight);
                    $set('prescription_items', PrescriptionService::merge($get('prescription_items') ?? [], [$item]));
                    self::notifyWarnings($livewire->getRecord()->patient, $item['medication']);
                }))
            ->values()
            ->all();
    }

    /**
     * Los datos propios del tratamiento (pieza y color en una resina, lote en un implante), arriba de la evolución.
     *
     * @return list<Component>
     */
    private static function procedureDetailFields(?Consultation $record): array
    {
        if (! $record) {
            return [];
        }

        return collect($record->procedureKind()->detailFields())
            ->map(fn (array $field, string $key) => match ($field['type']) {
                'toggle' => Toggle::make("procedure_details.{$key}")
                    ->label($field['label'])
                    ->inline(false),
                'select' => Select::make("procedure_details.{$key}")
                    ->label($field['label'])
                    ->options($field['options'] ?? []),
                'number' => TextInput::make("procedure_details.{$key}")
                    ->label($field['label'])
                    ->numeric()
                    ->minValue(0)
                    ->suffix($field['suffix'] ?? null),
                default => TextInput::make("procedure_details.{$key}")
                    ->label($field['label'])
                    ->placeholder($field['placeholder'] ?? null)
                    ->maxLength(120),
            })
            ->values()
            ->all();
    }

    /** @param  list<string>  $items */
    private static function bulletList(array $items): HtmlString
    {
        return new HtmlString('<ul class="consult-risks">'.collect($items)->map(fn (string $item) => '<li>'.e($item).'</li>')->implode('').'</ul>');
    }

    private static function diagnosisHint(?Consultation $record): string
    {
        $planned = $record ? self::plannedDiagnoses($record) : [];

        return match (true) {
            $planned !== [] => 'Del plan: '.implode(', ', $planned).'. Agrega aquí solo lo que encuentres de nuevo.',
            self::hidesOdontogram($record) => 'Agrégalo solo si encuentras algo.',
            default => 'Los del odontograma se agregan desde las sugerencias, debajo del dibujo.',
        };
    }

    /**
     * Diagnósticos de la atención donde se armó el plan, de las piezas que hoy se trabajan.
     *
     * @return list<string>
     */
    public static function plannedDiagnoses(Consultation $record): array
    {
        $planned = $record->plannedWork();
        $teeth = $planned->pluck('tooth')->filter()->map(fn (mixed $tooth) => (string) $tooth)->all();

        if ($teeth === []) {
            return [];
        }

        return $planned->map(fn (TreatmentPlanItem $item) => $item->plan->consultation)
            ->filter()
            ->unique(fn (Consultation $origin) => $origin->getKey())
            ->flatMap(fn (Consultation $origin) => $origin->diagnoses()->with('icd10Code')->get())
            ->filter(fn ($diagnosis) => in_array((string) $diagnosis->tooth, $teeth, true))
            ->map(fn ($diagnosis) => ($diagnosis->icd10Code?->description ?? $diagnosis->description).' · '.$diagnosis->tooth)
            ->unique()
            ->values()
            ->all();
    }

    /** Abre la ventana de antecedentes de la página (la misma desde cualquier aviso). */
    private static function askBackgroundAction(): Action
    {
        return Action::make('askBackground')
            ->label('Registrar antecedentes')
            ->icon(Heroicon::OutlinedHeart)
            ->button()
            ->size('sm')
            ->alpineClickHandler("\$wire.mountAction('editBackground')");
    }

    /**
     * Lo que muestra la barra fija: quién es, qué se le hace hoy, sus alertas, el consentimiento y qué secciones
     * ya tienen algo escrito.
     *
     * @return array<string, mixed>
     */
    public static function barData(?Consultation $record, mixed $livewire): array
    {
        $patient = $record?->patient;
        $data = (array) ($livewire->data ?? []);

        return [
            'patient' => $patient,
            'meta' => $record ? collect([
                $patient->age !== null ? $patient->age.' años' : null,
                $record->appointment?->treatment?->name,
                $record->attended_at->translatedFormat('D d/m · H:i'),
            ])->filter()->implode(' · ') : null,
            'alerts' => $patient ? ClinicalAlerts::for($patient) : [],
            'hasBackground' => $patient?->medicalBackground !== null,
            'consent' => $record ? self::consentStatus($record) : null,
            'autosavedAt' => $livewire->autosavedAt ?? null,
            'sections' => self::hidesOdontogram($record) ? Arr::except(self::SECTIONS, 'odontograma') : self::SECTIONS,
            'filled' => $record ? self::filledSections($record, $data) : [],
            'historyUrl' => $patient ? PatientResource::getUrl('clinical', ['record' => $patient]) : null,
        ];
    }

    /**
     * Si el tratamiento de la cita tiene su consentimiento: true si ya lo firmó para esta cita, false si falta.
     * Null si no aplica (sin cita, o el tratamiento no tiene consentimiento propio).
     */
    public static function consentStatus(Consultation $consultation): ?bool
    {
        $appointment = $consultation->appointment;
        $treatment = $appointment?->treatment;

        if (! $treatment || ! ConsentTemplate::query()->where('is_active', true)->where('treatment_id', $treatment->getKey())->exists()) {
            return null;
        }

        return Consent::query()
            ->where('patient_id', $consultation->patient_id)
            ->where('treatment_id', $treatment->getKey())
            ->whereNull('revoked_at')
            ->where('signed_at', '>=', $appointment->created_at->startOfDay())
            ->exists();
    }

    /**
     * Las secciones que ya tienen algo escrito, para marcarlas en la barra (el odontograma parte del anterior,
     * así que no cuenta).
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function filledSections(Consultation $consultation, array $data): array
    {
        // Un interruptor apagado no cuenta como dato escrito.
        $any = fn (mixed ...$values) => collect($values)->flatten()->contains(fn (mixed $value) => $value !== false && filled(is_string($value) ? trim($value) : $value));

        return array_keys(array_filter([
            'motivo' => $any($data['chief_complaint'] ?? null, $data['clinical_exam'] ?? null, $data['current_illness'] ?? null, Arr::except($data['vital_signs'] ?? [], 'weight')),
            'archivos' => $consultation->files()->exists(),
            'diagnostico' => filled($data['diagnoses'] ?? []) || $any($data['diagnosis_notes'] ?? null),
            'plan' => filled($data['treatments'] ?? []) || filled($data['planItems'] ?? []),
            'evolucion' => $any($data['procedures'] ?? null, $data['next_steps'] ?? null, $data['procedure_details'] ?? []),
            'indicaciones' => $any($data['indications'] ?? null) || self::hasMedications($data['prescription_items'] ?? []),
        ]));
    }

    /** @param  mixed  $items  Los renglones de la receta en pantalla. */
    public static function hasMedications(mixed $items): bool
    {
        return collect((array) $items)->contains(fn (mixed $item) => filled(trim((string) ($item['medication'] ?? ''))));
    }

    /** Signos vitales a la vista si ya tienen algo o si el paciente tiene una enfermedad que los pide. */
    private static function opensVitals(?Consultation $record): bool
    {
        if (! $record) {
            return false;
        }

        // El peso del niño se anota en la receta: no abre los signos vitales.
        return filled($record->current_illness)
            || collect(Arr::except($record->vital_signs ?? [], 'weight'))->filter(fn (mixed $value) => filled($value))->isNotEmpty()
            || self::riskConditions($record) !== []
            || $record->visitType() === VisitType::Urgent;
    }

    private static function vitalsHint(?Consultation $record): string
    {
        $conditions = $record ? self::riskConditions($record) : [];

        return $conditions === []
            ? 'Para cirugías, urgencias o pacientes con presión alta, diabetes o problemas del corazón.'
            : 'Tómalos antes de empezar: tiene '.mb_strtolower(implode(', ', $conditions)).'.';
    }

    /** @return list<string> */
    private static function riskConditions(Consultation $record): array
    {
        return collect($record->patient->medicalBackground?->conditions ?? [])
            ->intersect(self::VITALS_CONDITIONS)
            ->map(fn (string $key) => MedicalBackground::CONDITIONS[$key])
            ->values()
            ->all();
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

    /**
     * Lo que se cargará al terminar con lo marcado ahora en pantalla, en una línea.
     *
     * @param  mixed  $treatments  Ids de los tratamientos marcados.
     * @param  mixed  $planItems  Ids de los procedimientos del plan marcados.
     */
    public static function chargePreview(Consultation $consultation, mixed $treatments, mixed $planItems, mixed $prices = []): string
    {
        $lines = app(BillingService::class)->previewConsultation($consultation, array_values((array) $treatments), array_values((array) $planItems), (array) $prices);

        if ($lines === []) {
            return 'Al terminar no se carga nada: no hay nada marcado como hecho.';
        }

        $parts = collect($lines)->map(fn (array $line) => $line['label'].match (true) {
            $line['amount'] === null => '',
            $line['amount'] > 0 => ' '.Money::format($line['amount']),
            default => ' sin costo',
        });

        return 'Al terminar se carga: '.$parts->implode(' · ').'. Total '.Money::format(collect($lines)->sum(fn (array $line) => (float) $line['amount'])).'.';
    }

    /**
     * Lo que se cargará al terminar, una línea por cosa hecha con su precio. Llega el sugerido (el del plan o el
     * de lista) y el dentista lo cambia a su criterio, para más o para menos; si no lo toca, queda el sugerido.
     * Lo ya cargado (si se reabrió) y lo que va en la forma de pago del plan solo se muestra: eso se ve al cobrar.
     *
     * @param  mixed  $treatments  Ids de los tratamientos marcados.
     * @param  mixed  $planItems  Ids de los procedimientos del plan marcados.
     * @param  mixed  $prices  El precio de cada línea en pantalla ("item-12", "treatment-5").
     * @return list<Component>
     */
    public static function chargeLines(Consultation $consultation, mixed $treatments, mixed $planItems, mixed $prices): array
    {
        $lines = app(BillingService::class)->previewConsultation($consultation, array_values((array) $treatments), array_values((array) $planItems), (array) $prices);

        if ($lines === []) {
            return [Text::make(new HtmlString('<strong>Al terminar no se carga nada:</strong> no hay nada marcado como hecho.'))];
        }

        $rows = [Text::make('Al terminar se carga')->weight(FontWeight::Bold)];

        foreach ($lines as $line) {
            $changed = $line['editable'] && abs((float) $line['amount'] - (float) $line['suggested']) >= 0.005;
            $label = Text::make(new HtmlString(e($line['label']).($changed ? '<small class="consult-charge-suggested">Sugerido '.e(Money::format($line['suggested'])).'</small>' : '')));

            $rows[] = Flex::make([
                $label,
                $line['editable']
                    ? TextInput::make('prices.'.$line['key'])
                        ->label('Precio de '.$line['label'])
                        ->hiddenLabel()
                        ->prefix('S/')
                        ->numeric()
                        ->inputMode('decimal')
                        ->step(0.01)
                        ->minValue(0)
                        ->maxValue(99999)
                        ->live(onBlur: true)
                        // Si lo borra, vuelve el sugerido.
                        ->afterStateUpdated(fn (TextInput $component, mixed $state) => blank($state) ? $component->state(self::priceText($line['suggested'])) : null)
                        ->extraFieldWrapperAttributes(['class' => 'consult-charge-price'])
                        ->grow(false)
                    : Text::make(match (true) {
                        $line['amount'] === null => 'En su forma de pago',
                        $line['amount'] > 0 => Money::format($line['amount']),
                        default => 'Sin costo',
                    })->grow(false),
            ])->verticallyAlignCenter()->extraAttributes(['class' => 'consult-charge-line']);
        }

        $total = collect($lines)->sum(fn (array $line) => (float) $line['amount']);
        $suggested = collect($lines)->sum(fn (array $line) => (float) $line['suggested']);

        $rows[] = Flex::make([
            Text::make(new HtmlString('<strong>Total</strong>'.(abs($total - $suggested) >= 0.005 ? '<small class="consult-charge-suggested">Sugerido '.e(Money::format($suggested)).'</small>' : ''))),
            Text::make(Money::format($total))->weight(FontWeight::Bold)->grow(false),
        ])->verticallyAlignCenter()->extraAttributes(['class' => 'consult-charge-line consult-charge-total']);

        return $rows;
    }

    /**
     * El precio de cada línea marcada: el que el dentista ya puso o, en lo recién marcado, el sugerido.
     * Lo que se desmarcó se olvida.
     *
     * @param  mixed  $treatments  Ids de los tratamientos marcados.
     * @param  mixed  $planItems  Ids de los procedimientos del plan marcados.
     * @param  mixed  $prices  Los precios en pantalla.
     * @return array<string, string>
     */
    public static function pricesFor(Consultation $consultation, mixed $treatments, mixed $planItems, mixed $prices): array
    {
        $prices = (array) $prices;

        return collect(app(BillingService::class)->previewConsultation($consultation, array_values((array) $treatments), array_values((array) $planItems)))
            ->filter(fn (array $line) => $line['editable'])
            ->mapWithKeys(fn (array $line) => [$line['key'] => is_numeric($prices[$line['key']] ?? null) ? (string) $prices[$line['key']] : self::priceText($line['suggested'])])
            ->all();
    }

    /**
     * Lo que se guarda: solo los precios que el dentista cambió. Lo demás se carga al sugerido del momento de terminar.
     *
     * @param  mixed  $treatments  Ids de los tratamientos marcados.
     * @param  mixed  $planItems  Ids de los procedimientos del plan marcados.
     * @param  mixed  $prices  Los precios en pantalla.
     * @return array<string, float>|null
     */
    public static function changedPrices(Consultation $consultation, mixed $treatments, mixed $planItems, mixed $prices): ?array
    {
        $prices = (array) $prices;

        $changed = collect(app(BillingService::class)->previewConsultation($consultation, array_values((array) $treatments), array_values((array) $planItems)))
            ->filter(fn (array $line) => $line['editable'] && is_numeric($prices[$line['key']] ?? null) && abs(round(max(0, (float) $prices[$line['key']]), 2) - (float) $line['suggested']) >= 0.005)
            ->mapWithKeys(fn (array $line) => [$line['key'] => round(max(0, (float) $prices[$line['key']]), 2)])
            ->all();

        return $changed === [] ? null : $changed;
    }

    private static function priceText(?float $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * Si se marca del plan el mismo tratamiento que estaba aparte (lo programado en una cita suelta), deja de ir
     * aparte: es esa sesión del plan, y se cobra una sola vez, al precio del plan.
     *
     * @param  mixed  $treatments  Ids de los tratamientos marcados aparte.
     * @param  array<int|string>  $newPlanItems  Ids de los procedimientos del plan recién marcados.
     * @return list<string>
     */
    public static function withoutWhatThePlanCovers(mixed $treatments, array $newPlanItems): array
    {
        $treatments = array_map('strval', array_values((array) $treatments));
        $covered = TreatmentPlanItem::query()->whereKey($newPlanItems)->pluck('treatment_id')->filter()->map(fn (int $id) => (string) $id)->all();
        $kept = array_values(array_diff($treatments, $covered));

        if (count($kept) < count($treatments)) {
            Notification::make()
                ->info()
                ->title(Treatment::query()->whereKey(array_diff($treatments, $kept))->pluck('name')->implode(', ').' ya va como sesión del plan.')
                ->body('Se quitó de los tratamientos aparte para no cobrarlo dos veces.')
                ->send();
        }

        return $kept;
    }

    /** @return Collection<int, TreatmentPlanItem> */
    private static function workableItems(Consultation $consultation): Collection
    {
        return app(TreatmentPlanService::class)->workableItems($consultation->patient, $consultation);
    }

    /** Suma un paquete al plan del paciente. Si vino por una campaña que ofrece un paquete, ya viene elegido. */
    private static function addPackageAction(): Action
    {
        return Action::make('addPackage')
            ->label('Agregar paquete')
            ->icon(Heroicon::OutlinedGift)
            ->color('gray')
            ->link()
            ->visible(fn (?Consultation $record) => $record && auth()->user()->can('update', $record) && TreatmentPackage::query()->active()->exists())
            ->modalHeading('Agregar un paquete al plan')
            ->modalDescription(fn (Consultation $record) => ($campaign = self::campaignWithPackage($record))
                ? 'Viene por la campaña '.$campaign->name.'.'
                : 'Sus tratamientos se suman al borrador del plan, con el precio del paquete repartido.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitActionLabel('Agregar al plan')
            ->fillForm(fn (Consultation $record) => ['package_id' => self::campaignWithPackage($record)?->treatment_package_id])
            ->schema(fn (Consultation $record) => [
                Select::make('package_id')
                    ->label('Paquete')
                    ->options(fn () => TreatmentPackage::query()->active()->orderBy('name')->get()
                        ->mapWithKeys(fn (TreatmentPackage $package) => [$package->getKey() => $package->name.' · '.Money::format($package->price)]))
                    ->required()
                    ->live(),
                Text::make(function (Get $get) {
                    $package = TreatmentPackage::query()->active()->find($get('package_id'));

                    return $package ? collect([
                        'Incluye: '.$package->contents().'.',
                        'Precio normal '.Money::format($package->normalPrice()).', en el paquete '.Money::format($package->price).($package->savings() > 0 ? ' (ahorra '.Money::format($package->savings()).')' : '').'.',
                        ...TreatmentPlanService::packagePaymentOptions($package),
                    ])->implode(' ') : '';
                })
                    ->visible(fn (Get $get) => filled($get('package_id'))),
                TreatmentPlanForm::companionSelect($record->patient)
                    ->visible(fn (Get $get) => (bool) TreatmentPackage::query()->active()->find($get('package_id'))?->for_two),
            ])
            ->action(function (Consultation $record, array $data) {
                /** @var User $user */
                $user = auth()->user();
                $package = TreatmentPackage::query()->active()->findOrFail($data['package_id']);
                $companion = filled($data['companion_patient_id'] ?? null) ? Patient::find($data['companion_patient_id']) : null;

                try {
                    [, $companionPlan] = app(TreatmentPlanService::class)->addPackageToDraft($record, $user, $package, $companion);
                } catch (ClinicalException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Se agregó '.$package->name.' al plan ('.Money::format($package->price).').')
                    ->body($companionPlan ? '2x1: se armó el plan de '.$companion->full_name.', sin costo.' : null)
                    ->send();
            });
    }

    /** La campaña por la que vino a la cita, si ofrece un paquete que se sigue ofreciendo. */
    private static function campaignWithPackage(Consultation $consultation): ?Campaign
    {
        $campaign = $consultation->appointment?->campaign;

        return $campaign && CampaignText::package($campaign) ? $campaign : null;
    }

    /** Guarda y abre la receta lista para imprimir (o guardar en PDF). */
    private static function printPrescriptionAction(): Action
    {
        return Action::make('printPrescription')
            ->label('Imprimir')
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->link()
            // Sin antecedentes no se sabe si es alérgico: se puede imprimir igual, pero que sea a sabiendas.
            ->requiresConfirmation(fn (?Consultation $record) => $record && ! $record->patient->medicalBackground)
            ->modalHeading('¿Imprimir sin antecedentes?')
            ->modalDescription(self::WITHOUT_BACKGROUND)
            ->modalSubmitActionLabel('Imprimir igual')
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
            ->modalDescription(fn (Consultation $record) => $record->patient->medicalBackground
                ? 'Revisa el mensaje. Se abrirá WhatsApp con el texto listo: solo presiona Enviar.'
                : '⚠ '.self::WITHOUT_BACKGROUND)
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
            ->label('Guardar como opción')
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
}
