<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * La dirección de la antigua página "Nueva cita" (marcadores y enlaces guardados). Nueva cita es una sola, la ventana
 * sobre la agenda: quien llega aquí pasa a ella con lo mismo que traía el enlace.
 */
class CreateAppointment extends CreateRecord
{
    protected static string $resource = AppointmentResource::class;

    public function mount(): void
    {
        $this->redirect(AppointmentResource::newUrl(request()->only(AppointmentResource::NEW_APPOINTMENT_ARGUMENTS)));
    }
}
