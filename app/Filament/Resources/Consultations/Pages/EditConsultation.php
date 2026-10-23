<?php

namespace App\Filament\Resources\Consultations\Pages;

use App\Exceptions\ClinicalException;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\ClinicalTemplate;
use App\Models\Consultation;
use App\Services\Clinical\ConsultationService;
use App\Services\Clinical\PrescriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;

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
