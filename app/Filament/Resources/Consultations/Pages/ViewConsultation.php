<?php

namespace App\Filament\Resources\Consultations\Pages;

use App\Exceptions\ClinicalException;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Consultations\ConsultationResource;
use App\Filament\Resources\Patients\PatientResource;
use App\Models\Appointment;
use App\Models\Consultation;
use App\Services\Clinical\ConsultationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewConsultation extends ViewRecord
{
    protected static string $resource = ConsultationResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'Atención de '.$this->getConsultation()->patient->full_name;
    }

    public function getBreadcrumb(): string
    {
        return $this->getConsultation()->attended_at->format('d/m/Y');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('continue')
                ->label('Continuar')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->visible(fn () => auth()->user()->can('update', $this->getConsultation()))
                ->url(fn () => ConsultationResource::getUrl('edit', ['record' => $this->getConsultation()])),
            Action::make('reopen')
                ->label('Reabrir para corregir')
                ->icon(Heroicon::OutlinedLockOpen)
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('Podrás corregir la atención. Cada cambio queda registrado con fecha y usuario.')
                ->authorize('reopen')
                ->action(function () {
                    try {
                        app(ConsultationService::class)->reopen($this->getConsultation(), auth()->user());
                    } catch (ClinicalException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $this->redirect(ConsultationResource::getUrl('edit', ['record' => $this->getConsultation()]));
                }),
            Action::make('book')
                ->label('Programar cita')
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->visible(fn () => auth()->user()->can('create', Appointment::class))
                ->url(fn () => AppointmentResource::getUrl('create', ['patient' => $this->getConsultation()->patient_id, 'doctor' => $this->getConsultation()->doctor_id])),
            Action::make('clinicalRecord')
                ->label('Historia clínica')
                ->icon(Heroicon::OutlinedFolderOpen)
                ->color('gray')
                ->url(fn () => PatientResource::getUrl('clinical', ['record' => $this->getConsultation()->patient])),
        ];
    }

    private function getConsultation(): Consultation
    {
        /** @var Consultation */
        return $this->getRecord();
    }
}
