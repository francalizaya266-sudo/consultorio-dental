<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Schemas\DoctorForm;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;

class CreateDoctor extends CreateRecord
{
    protected static string $resource = DoctorResource::class;

    protected static ?string $title = 'Nuevo doctor';

    protected static ?string $breadcrumb = 'Nuevo';

    protected static bool $canCreateAnother = false;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return DoctorForm::withAccess($data, $this->form->getRawState());
    }

    /** Recién creado casi siempre le falta algo para atender: se dice qué, en vez de un "Creado" a secas. */
    protected function getCreatedNotification(): ?Notification
    {
        $missing = DoctorForm::missingToAttend($this->getRecord());

        return Notification::make()
            ->success()
            ->title('Doctor guardado')
            ->body($missing === []
                ? 'Cuando su ficha esté completa, enciende "Aparece en la web".'
                : 'Para poder agendarle falta '.implode(' y ', $missing).'. Agrégalo aquí abajo.');
    }

    /** Al crear, se sigue en la ficha para agregar sus tratamientos. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
