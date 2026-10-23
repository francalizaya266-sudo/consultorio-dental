<?php

namespace App\Filament\Resources\Consultations\Pages;

use App\Enums\DiagnosisType;
use App\Exceptions\ClinicalException;
use App\Filament\Actions\SignConsentAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\ClinicalTemplate;
use App\Models\Consultation;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\PrescriptionService;
use App\Services\Clinical\TreatmentPlanService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
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
    }

    /** Medicamentos del formulario: se guardan aparte, en la receta de la atención. */
    private array $prescriptionItems = [];

    public function getTitle(): string|Htmlable
    {
        return 'Atención de '.$this->getConsultation()->patient->full_name;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $consultation = $this->getConsultation();

        return collect([
            $consultation->attended_at->translatedFormat('l d/m/Y · H:i'),
            $consultation->appointment?->treatment?->name,
            $consultation->patient->age !== null ? $consultation->patient->age.' años' : null,
        ])->filter()->implode(' · ');
    }

    public function getBreadcrumb(): string
    {
        return 'Atender';
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->templateAction(),
            SignConsentAction::make(fn () => $this->getConsultation()->patient, fn () => $this->getConsultation()->appointment?->treatment)
                ->label('Consentimiento')
                ->color('gray'),
            Action::make('clinicalRecord')
                ->label('Historia clínica')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->color('gray')
                ->url(fn () => PatientResource::getUrl('clinical', ['record' => $this->getConsultation()->patient]))
                ->openUrlInNewTab(),
            $this->closeAction(),
        ];
    }

    protected function getFormActions(): array
    {
        return [
            $this->getSaveFormAction()->label('Guardar'),
            $this->closeAction(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        return [...$data, 'prescription_items' => app(PrescriptionService::class)->itemsOf($this->getConsultation())];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->prescriptionItems = Arr::pull($data, 'prescription_items') ?? [];

        return $data;
    }

    protected function afterSave(): void
    {
        app(PrescriptionService::class)->syncForConsultation($this->getConsultation(), $this->prescriptionItems);
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

        foreach ($worked as $item) {
            if (! in_array((string) $item->getKey(), $current, true)) {
                $current[] = (string) $item->getKey();
            }
        }

        $this->data['planItems'] = $current;

        if ($worked->isNotEmpty()) {
            Notification::make()
                ->success()
                ->title('Marcado como trabajado hoy: '.$worked->map->label()->implode(', ').'.')
                ->body('Se cobra al terminar la atención. Si no se hizo hoy, desmárcalo en el plan.')
                ->send();
        }
    }

    /** Guarda, cierra la atención, marca la cita como atendida y ofrece programar la siguiente. */
    private function closeAction(): Action
    {
        return Action::make('close')
            ->label('Terminar atención')
            ->icon(Heroicon::OutlinedCheckCircle)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('¿Terminar la atención?')
            ->modalDescription('Se guarda lo escrito y la cita queda como atendida. Si algo quedó mal, puedes reabrirla durante 24 horas.')
            ->modalSubmitActionLabel('Sí, terminar')
            ->action(function () {
                $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                $consultation = $this->getConsultation();

                try {
                    app(ConsultationService::class)->close($consultation, auth()->user());
                } catch (ClinicalException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title('Atención terminada')
                    ->body('¿El paciente necesita otra cita? Prográmala ahora para que se lleve la fecha.')
                    ->actions([
                        Action::make('book')
                            ->label('Programar próxima cita')
                            ->button()
                            ->url(AppointmentResource::getUrl('create', ['patient' => $consultation->patient_id, 'doctor' => $consultation->doctor_id])),
                    ])
                    ->persistent()
                    ->send();

                $this->redirect(ConsultationResource::getUrl('view', ['record' => $consultation]));
            });
    }

    /** Suma al formulario el texto de una plantilla (sin borrar lo escrito). */
    private function templateAction(): Action
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

    private function getConsultation(): Consultation
    {
        /** @var Consultation */
        return $this->getRecord();
    }
}
