<?php

namespace App\Filament\Resources\Consultations\Schemas;

use App\Enums\DiagnosisType;
use App\Enums\PlanItemStatus;
use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
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
use App\Services\Clinical\AllergyCheck;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\CampaignText;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

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

                self::step(1, 'Antes de empezar', 'Lo que cuenta el paciente y lo que encuentras al examinarlo.'),

                Callout::make('Faltan los antecedentes médicos')
                    ->key('backgroundMissing')
                    ->description('Pregunta por alergias, enfermedades y medicamentos antes de empezar. Se registra aquí mismo, sin salir de la atención.')
                    ->warning()
                    ->actions([self::askBackgroundAction()])
                    ->visible(fn (?Consultation $record) => $record && ! $record->patient->medicalBackground),

                Section::make('Motivo y examen')
                    ->id('motivo')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
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
                                Grid::make(['default' => 1, 'sm' => 3])
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

                self::step(2, 'Lo que hiciste', 'Odontograma, diagnóstico, lo que se cobra y cómo fue.'),

                Grid::make(['default' => 1, 'lg' => 5])
                    ->extraAttributes(['class' => 'consultation-split'])
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
                        ])->columnSpan(['lg' => 3]),
                        Group::make([
                            Section::make('Diagnóstico')
                                ->id('diagnostico')
                                ->key('diagnosis')
                                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                                ->description('Los del odontograma se agregan desde las sugerencias, debajo del dibujo.')
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
                                        ->visible(fn (?Consultation $record) => ! $record || self::workableItems($record)->isEmpty()),
                                    Group::make(fn (Get $get, ?Consultation $record) => $record ? self::chargeLines($record, $get('treatments'), $get('planItems'), $get('prices')) : [])
                                        ->extraAttributes(['class' => 'consult-charges']),
                                ]),

                            Section::make('Nota de evolución')
                                ->id('evolucion')
                                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                                ->description('Cómo fue: anestesia, técnica, materiales e incidencias.')
                                ->schema([
                                    QuickTextarea::make('procedures')
                                        ->hiddenLabel()
                                        ->label('Nota de evolución')
                                        ->snippets(SnippetField::Procedures),
                                    QuickTextarea::make('next_steps')
                                        ->label('Próximos pasos')
                                        ->placeholder('Ej.: resina en 36 la próxima sesión')
                                        ->snippets(SnippetField::Any),
                                ]),
                        ])->columnSpan(['lg' => 2]),
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
                                // Avisa si el medicamento choca con una alergia registrada.
                                Text::make(fn (Get $get, EditConsultation $livewire) => '⚠ '.AllergyCheck::warning($livewire->getRecord()->patient, $get('medication')))
                                    ->color('danger')
                                    ->weight(FontWeight::Bold)
                                    ->visible(fn (Get $get, EditConsultation $livewire) => AllergyCheck::warning($livewire->getRecord()->patient, $get('medication')) !== null)
                                    ->columnSpanFull(),
                            ]),
                        Actions::make(fn () => self::medicationSnippets()
                            ->map(fn (TextSnippet $snippet) => Action::make('addMedication'.$snippet->getKey())
                                ->label($snippet->label)
                                ->icon(Heroicon::OutlinedPlus)
                                ->size('sm')
                                ->color('gray')
                                ->outlined()
                                ->action(function (Get $get, Set $set, EditConsultation $livewire) use ($snippet) {
                                    $item = app(PrescriptionService::class)->fromSnippet($snippet);
                                    $set('prescription_items', PrescriptionService::merge($get('prescription_items') ?? [], [$item]));
                                    $snippet->recordUse(auth()->user());

                                    if ($warning = AllergyCheck::warning($livewire->getRecord()->patient, $item['medication'])) {
                                        Notification::make()->danger()->title($warning)->persistent()->send();
                                    }
                                }))
                            ->all()),
                    ]),
            ]);
    }

    /** Separador de cada paso de la atención: número, nombre y para qué sirve. */
    private static function step(int $number, string $title, string $description): View
    {
        return View::make('filament.clinical.consultation-step')
            ->key('step'.$number)
            ->viewData(['number' => $number, 'title' => $title, 'description' => $description]);
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
            'sections' => self::SECTIONS,
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
        $any = fn (mixed ...$values) => collect($values)->flatten()->contains(fn (mixed $value) => filled(is_string($value) ? trim($value) : $value));

        return array_keys(array_filter([
            'motivo' => $any($data['chief_complaint'] ?? null, $data['clinical_exam'] ?? null, $data['current_illness'] ?? null, $data['vital_signs'] ?? []),
            'archivos' => $consultation->files()->exists(),
            'diagnostico' => filled($data['diagnoses'] ?? []) || $any($data['diagnosis_notes'] ?? null),
            'plan' => filled($data['treatments'] ?? []) || filled($data['planItems'] ?? []),
            'evolucion' => $any($data['procedures'] ?? null, $data['next_steps'] ?? null),
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

        return filled($record->current_illness)
            || collect($record->vital_signs ?? [])->filter(fn (mixed $value) => filled($value))->isNotEmpty()
            || self::riskConditions($record) !== [];
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
