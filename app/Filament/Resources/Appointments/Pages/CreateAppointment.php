<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Exceptions\BookingException;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Support\SlotPicker;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Booking\AppointmentService;
use App\Services\Clinical\TreatmentPlanService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    protected static ?string $title = 'Nueva cita';

    protected static ?string $breadcrumb = 'Nueva';

    protected static bool $canCreateAnother = false;

    /** La cita se crea con la misma lógica que la web: nunca se cruzan dos citas. */
    protected function handleRecordCreation(array $data): Model
    {
        $patient = Patient::findOrFail($data['patient_id']);
        $planItem = filled($data['treatment_plan_item_id'] ?? null)
            ? app(TreatmentPlanService::class)->workableItems($patient)->firstWhere('id', (int) $data['treatment_plan_item_id'])
            : null;

        try {
            $appointment = app(AppointmentService::class)->bookFromStaff(
                auth()->user(),
                $patient,
                Doctor::findOrFail($data['doctor_id']),
                Treatment::findOrFail($data['treatment_id']),
                SlotPicker::startsAt($data),
                [
                    'origin' => $data['origin'] ?? null,
                    'fixed_date' => (bool) ($data['fixed_date'] ?? false),
                    'internal_notes' => $data['internal_notes'] ?? null,
                    'treatment_plan_item_id' => $planItem?->getKey(),
                ],
            );

            // Si agenda una sesión del plan, el paciente lo aceptó.
            if ($planItem) {
                app(TreatmentPlanService::class)->accept($planItem->plan);
            }

            return $appointment;
        } catch (BookingException|AuthorizationException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
            $this->halt();
        }
    }

    protected function getCreateFormAction(): Action
    {
        return parent::getCreateFormAction()->label('Agendar cita');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Cita creada. El aviso al paciente está listo en "Avisos por enviar".';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
