<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Doctors\Schemas\DoctorForm;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditDoctor extends EditRecord
{
    protected static string $resource = DoctorResource::class;

    /** @param  array<string, mixed>  $data */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return DoctorForm::withAccess($data, $this->form->getRawState());
    }

    protected function getHeaderActions(): array
    {
        return [
            // En el menú ⋮, no como botón rojo. Con citas o historias no se elimina: explica que se apaga "Activo".
            ActionGroup::make([
                DeleteAction::make()
                    ->authorizationNotification()
                    ->modalDescription('Deja de aparecer en la agenda y en la web. Se puede recuperar.'),
                RestoreAction::make(),
            ]),
        ];
    }
}
