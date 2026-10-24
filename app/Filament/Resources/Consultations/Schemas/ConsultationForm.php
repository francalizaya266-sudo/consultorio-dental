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
use App\Filament\Resources\Patients\Schemas\TreatmentPlanForm;
use App\Filament\Support\ClinicalAlerts;
use App\Livewire\OdontogramChart;
use App\Models\Campaign;
use App\Models\Consultation;
use App\Models\Icd10Code;
use App\Models\Patient;
use App\Models\Prescription;
use App\Models\TextSnippet;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlanItem;
use App\Models\User;
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

/**
 * Pantalla de la atención: todo lo que el doctor escribe mientras atiende. Arriba, una barra fija con el
 * paciente, sus alertas, las secciones y los botones de guardar y terminar. En la tablet horizontal
 * (y en la computadora) el odontograma va a la izquierda y las notas a la derecha.
 */
class ConsultationForm
{
    /** @var array<string, string> Secciones de la barra fija: ancla → nombre. */
    public const SECTIONS = [
        'motivo' => 'Motivo',
        'odontograma' => 'Odontograma',
        'diagnostico' => 'Diagnóstico',
        'plan' => 'Plan',
        'evolucion' => 'Evolución',
        'indicaciones' => 'Indicaciones',
        'archivos' => 'Fotos',
        'receta' => 'Receta',
    ];

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                View::make('filament.clinical.consultation-bar')
                    ->viewData(fn (?Consultation $record, $livewire) => [
                        'patient' => $record?->patient,
                        'alerts' => $record ? ClinicalAlerts::for($record->patient) : [],
                        'autosavedAt' => $livewire->autosavedAt ?? null,
                        'sections' => self::SECTIONS,
                    ]),

                Group::make(fn (?Consultation $record) => array_filter([ClinicalAlerts::callout($record?->patient)])),

                Section::make('Motivo y examen')
                    ->id('motivo')
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
                                        ->snippets(SnippetField::Diagnosis),
                                ]),

                            Section::make('Plan de tratamiento')
                                ->id('plan')
                                ->key('plan')
                                ->icon(Heroicon::OutlinedClipboardDocumentList)
                                ->description('Marca lo que se trabajó hoy. Al terminar la atención, el avance del plan se actualiza solo.')
                                ->headerActions([self::addPackageAction()])
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
                                        ->bulkToggleable(false)
                                        ->visible(fn (?Consultation $record) => $record && self::workableItems($record)->isNotEmpty()),
                                    Text::make('El paciente no tiene un plan de tratamiento vigente. Ármalo desde las sugerencias que salen debajo del odontograma.')
                                        ->color('gray')
                                        ->visible(fn (?Consultation $record) => ! $record || self::workableItems($record)->isEmpty()),
                                ]),

                            Section::make('Evolución')
                                ->id('evolucion')
                                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                                ->description('Qué se hizo hoy.')
                                ->schema([
                                    QuickTextarea::make('procedures')
                                        ->hiddenLabel()
                                        ->label('Evolución')
                                        ->snippets(SnippetField::Procedures),
                                ]),

                            Section::make('Indicaciones y próximos pasos')
                                ->id('indicaciones')
                                ->icon(Heroicon::OutlinedClipboardDocument)
                                ->schema([
                                    QuickTextarea::make('indications')
                                        ->label('Indicaciones para el paciente')
                                        ->snippets(SnippetField::Indications),
                                    QuickTextarea::make('next_steps')
                                        ->label('Próximos pasos')
                                        ->placeholder('Ej.: resina en 36 la próxima sesión')
                                        ->snippets(SnippetField::Any),
                                ]),
                        ])->columnSpan(['lg' => 2]),
                    ]),

                Section::make('Radiografías y fotos')
                    ->id('archivos')
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

                Section::make('Receta')
                    ->id('receta')
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
}
