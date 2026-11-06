<?php

namespace App\Filament\Resources\Consultations\Pages;

use App\Enums\DiagnosisType;
use App\Exceptions\ClinicalException;
use App\Filament\Actions\EditMedicalBackgroundAction;
use App\Filament\Actions\SignConsentAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Consultations\Schemas\ConsultationForm;
use App\Filament\Resources\Consultations\Schemas\SuppliesUsedField;
use App\Models\ClinicalTemplate;
use App\Models\Consultation;
use App\Models\Supply;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use App\Services\Inventory\InventoryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Enums\Width;
use Filament\Support\Exceptions\Halt;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;

/** Pantalla donde el doctor escribe mientras atiende. */
class EditConsultation extends EditRecord
{
    protected static string $resource = ConsultationResource::class;

    /** Si la atención ya se cerró o es de otro doctor, se abre en modo lectura. */
    public function mount(int|string $record): void
    {
        $consultation = $this->resolveRecord($record);

        if (! auth()->user()->can('update', $consultation) && auth()->user()->can('view', $consultation)) {
            throw new HttpResponseException(redirect(ConsultationResource::getUrl('view', ['record' => $consultation])));
        }

        parent::mount($record);

        $this->savedFingerprint = $this->fingerprint();
    }

    /** Hora del último guardado automático, para mostrarla en la barra fija. */
    #[Locked]
    public ?string $autosavedAt = null;

    /** Huella de lo último guardado: el guardado automático solo guarda si algo cambió. */
    #[Locked]
    public ?string $savedFingerprint = null;

    /** Mientras se guarda solo, lo incompleto no se marca en pantalla ni mueve la página. */
    private bool $isAutosaving = false;

    private bool $autosaveFailed = false;

    /** Medicamentos del formulario: se guardan aparte, en la receta de la atención. */
    private array $prescriptionItems = [];

    /** Notas del diagnóstico: se abren con "Agregar una nota" (o solas, si ya tienen algo). */
    public bool $showDiagnosisNotes = false;

    public function getTitle(): string|Htmlable
    {
        return 'Atención de '.$this->getConsultation()->patient->full_name;
    }

    /** Sin encabezado grande: la barra fija hace de encabezado, con el nombre, la cita y Terminar. */
    public function getHeading(): string|Htmlable
    {
        return '';
    }

    public function getBreadcrumb(): string
    {
        return 'Atender';
    }

    /** Las acciones viven en la barra fija (siempre a la vista), no arriba ni al final de la página. */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getFormActions(): array
    {
        return [];
    }

    public function signConsentAction(): Action
    {
        return SignConsentAction::make(fn () => $this->getConsultation()->patient, fn () => $this->getConsultation()->appointment?->treatment)
            ->label('Consentimiento');
    }

    public function editBackgroundAction(): Action
    {
        return EditMedicalBackgroundAction::make(fn () => $this->getConsultation()->patient);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'prescription_items' => app(PrescriptionService::class)->itemsOf($this->getConsultation())];
    }

    /** Cada línea de lo hecho llega con su precio: el que puso el dentista o, si no lo cambió, el sugerido. */
    protected function afterFill(): void
    {
        $this->data['prices'] = ConsultationForm::pricesFor($this->getConsultation(), $this->data['treatments'] ?? [], $this->data['planItems'] ?? [], $this->getConsultation()->prices ?? []);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->prescriptionItems = Arr::pull($data, 'prescription_items') ?? [];
        $data['prices'] = ConsultationForm::changedPrices($this->getConsultation(), $this->data['treatments'] ?? [], $this->data['planItems'] ?? [], $this->data['prices'] ?? []);

        return $data;
    }

    protected function afterSave(): void
    {
        app(PrescriptionService::class)->syncForConsultation($this->getConsultation(), $this->prescriptionItems);
        $this->savedFingerprint = $this->fingerprint();
        $this->autosavedAt = now()->format('H:i');
    }

    /**
     * Guardado automático del borrador (lo llama la barra fija cada pocos segundos), como el odontograma.
     * Si falta completar algo (por ejemplo, un diagnóstico sin código), espera al guardado a mano.
     */
    public function autosave(): void
    {
        if (! auth()->user()->can('update', $this->getConsultation()) || $this->fingerprint() === $this->savedFingerprint) {
            $this->skipRender();

            return;
        }

        $this->isAutosaving = true;
        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
        $this->isAutosaving = false;

        if ($this->autosaveFailed) {
            $this->resetErrorBag();
            $this->skipRender();

            return;
        }

        $this->autosavedAt = now()->format('H:i');
    }

    protected function onValidationError(ValidationException $exception): void
    {
        if ($this->isAutosaving) {
            $this->autosaveFailed = true;

            throw new Halt;
        }

        parent::onValidationError($exception);
    }

    private function fingerprint(): string
    {
        return md5((string) json_encode($this->data));
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Guardado';
    }

    /**
     * Lo elegido en las sugerencias del odontograma: los procedimientos ya quedaron en el plan, y los
     * diagnósticos se guardan y se suman a la lista sin tocar lo que el doctor está escribiendo.
     *
     * @param  list<array{icd10_code_id: int, tooth: string}>  $diagnoses
     */
    #[On('odontogram-suggestions-applied')]
    public function addFromOdontogram(array $diagnoses = [], int $planned = 0, string $plannedTotal = ''): void
    {
        $consultation = $this->getConsultation();

        if (! auth()->user()->can('update', $consultation)) {
            return;
        }

        $state = collect($this->data['diagnoses'] ?? []);
        $added = 0;

        foreach ($diagnoses as $row) {
            $exists = $state->contains(fn (array $item) => (int) ($item['icd10_code_id'] ?? 0) === (int) $row['icd10_code_id'] && (string) ($item['tooth'] ?? '') === (string) $row['tooth']);

            if ($exists) {
                continue;
            }

            $diagnosis = $consultation->diagnoses()->create(['icd10_code_id' => $row['icd10_code_id'], 'tooth' => $row['tooth'], 'type' => DiagnosisType::Definitive]);
            $state->put('record-'.$diagnosis->getKey(), ['icd10_code_id' => $diagnosis->icd10_code_id, 'tooth' => $diagnosis->tooth, 'type' => DiagnosisType::Definitive->value]);
            $added++;
        }

        $this->data['diagnoses'] = $state->all();

        $parts = array_filter([
            $planned ? ($planned === 1 ? '1 procedimiento' : "{$planned} procedimientos").' al plan'.($plannedTotal ? " ({$plannedTotal})" : '') : null,
            $added ? ($added === 1 ? '1 diagnóstico' : "{$added} diagnósticos") : null,
        ]);

        Notification::make()
            ->title($parts ? 'Se agregaron '.implode(' y ', $parts).'.' : 'Eso ya estaba agregado.')
            ->status($parts ? 'success' : 'info')
            ->send();
    }

    /**
     * "Tratado" en el odontograma: el procedimiento del plan queda marcado como trabajado hoy, a la
     * vista para desmarcarlo. Se cobra recién al terminar la atención, como lo marcado a mano.
     *
     * @param  list<int>  $items
     */
    #[On('odontogram-plan-items-worked')]
    public function markPlanItemsWorked(array $items = []): void
    {
        $consultation = $this->getConsultation();

        if (! auth()->user()->can('update', $consultation)) {
            return;
        }

        $worked = app(TreatmentPlanService::class)->workableItems($consultation->patient, $consultation)->whereIn('id', $items);
        $current = array_map('strval', $this->data['planItems'] ?? []);
        $added = [];

        foreach ($worked as $item) {
            if (! in_array((string) $item->getKey(), $current, true)) {
                $current[] = $added[] = (string) $item->getKey();
            }
        }

        $this->data['planItems'] = $current;

        if ($worked->isNotEmpty()) {
            Notification::make()
                ->success()
                ->title('Marcado como trabajado hoy: '.$worked->map->label()->implode(', ').'.')
                ->body('Se cobra al terminar la atención. Si no se hizo hoy, desmárcalo en "Lo que se hizo hoy".')
                ->send();
        }

        $this->data['treatments'] = ConsultationForm::withoutWhatThePlanCovers($this->data['treatments'] ?? [], $added);
        $this->data['prices'] = ConsultationForm::pricesFor($consultation, $this->data['treatments'], $current, $this->data['prices'] ?? []);
    }

    /** Guarda, cierra la atención, marca la cita como atendida y ofrece programar la siguiente. */
    public function closeAction(): Action
    {
        return Action::make('close')
            ->label('Terminar atención')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            // Sin inventario es solo una confirmación; con inventario, además, lo que sale de él.
            ->requiresConfirmation(fn () => ! self::tracksInventory())
            ->modalHeading('¿Terminar la atención?')
            ->modalDescription(fn () => ConsultationForm::chargePreview($this->getConsultation(), $this->data['treatments'] ?? [], $this->data['planItems'] ?? [], $this->data['prices'] ?? []).' '.(self::tracksInventory()
                ? 'Se guarda lo escrito, la cita queda como atendida y estos materiales salen del inventario. Borra lo que no usaste y agrega lo que usaste de más. Si algo quedó mal, puedes reabrirla durante 24 horas.'
                : 'Se guarda lo escrito y la cita queda como atendida. Si algo quedó mal, puedes reabrirla durante 24 horas.'))
            ->modalWidth(fn () => self::tracksInventory() ? Width::ThreeExtraLarge : null)
            ->modalSubmitActionLabel('Sí, terminar')
            ->fillForm(fn () => self::tracksInventory()
                ? ['supplies_used' => app(InventoryService::class)->plannedUsage($this->getConsultation(), $this->data['planItems'] ?? null, $this->data['treatments'] ?? null)]
                : [])
            ->schema(fn () => self::tracksInventory() ? [SuppliesUsedField::make()] : [])
            ->action(function (array $data) {
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                $consultation = $this->getConsultation();

                try {
                    app(ConsultationService::class)->close($consultation, auth()->user(), array_key_exists('supplies_used', $data) ? ($data['supplies_used'] ?? []) : null);
                } catch (ClinicalException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                $next = app(TreatmentPlanService::class)->nextToSchedule($consultation->patient);

                Notification::make()
                    ->success()
                    ->title('Atención terminada')
                    ->body($next
                        ? 'Sigue '.$next->label().' de su plan. Prográmala ahora para que se lleve la fecha.'
                        : '¿El paciente necesita otra cita? Prográmala ahora para que se lleve la fecha.')
                    ->actions([
                        Action::make('book')
                            ->label('Programar próxima cita')
                            ->button()
                            ->url(AppointmentResource::nextVisitUrl($consultation->patient, $consultation->doctor_id)),
                    ])
                    ->persistent()
                    ->send();

                $this->redirect(ConsultationResource::getUrl('view', ['record' => $consultation]));
            });
    }

    /** Suma al formulario el texto de una plantilla (sin borrar lo escrito). */
    public function templateAction(): Action
    {
        return Action::make('template')
            ->label('Usar plantilla')
            ->icon(Heroicon::OutlinedDocumentDuplicate)
            ->color('gray')
            ->modalHeading('Usar plantilla')
            ->modalDescription('Llena los campos vacíos y agrega el texto al final de los que ya tienen algo.')
            ->modalSubmitActionLabel('Usar')
            ->schema([
                Select::make('template')
                    ->label('Plantilla')
                    ->options(fn () => ClinicalTemplate::query()
                        ->where('is_active', true)
                        ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', auth()->id()))
                        ->orderBy('name')
                        ->pluck('name', 'id'))
                    ->default(fn () => app(ConsultationService::class)->templateFor($this->getConsultation()->appointment?->treatment, auth()->user())?->getKey())
                    ->required(),
            ])
            ->action(function (array $data) {
                $template = ClinicalTemplate::find($data['template']);

                if ($template) {
                    $this->data = [
                        ...$this->data,
                        ...ConsultationService::mergeTemplate($this->data, $template),
                        'prescription_items' => PrescriptionService::merge($this->data['prescription_items'] ?? [], app(PrescriptionService::class)->fromTemplate($template)),
                    ];
                    Notification::make()->success()->title('Plantilla aplicada. Revisa y guarda.')->send();
                }
            });
    }

    /** Si la clínica lleva inventario: entonces al terminar se revisa lo que sale de él. */
    private static function tracksInventory(): bool
    {
        return once(fn () => Supply::query()->active()->exists());
    }

    private function getConsultation(): Consultation
    {
        /** @var Consultation */
        return $this->getRecord();
    }
}
