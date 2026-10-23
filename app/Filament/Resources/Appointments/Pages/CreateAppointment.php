<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Exceptions\BookingException;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Support\SlotPicker;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Booking\AppointmentService;
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
        try {
            return app(AppointmentService::class)->bookFromStaff(
                auth()->user(),
                Patient::findOrFail($data['patient_id']),
                Doctor::findOrFail($data['doctor_id']),
                Treatment::findOrFail($data['treatment_id']),
                SlotPicker::startsAt($data),
                [
                    'origin' => $data['origin'] ?? null,
                    'fixed_date' => (bool) ($data['fixed_date'] ?? false),
                    'internal_notes' => $data['internal_notes'] ?? null,
                ],
            );
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
