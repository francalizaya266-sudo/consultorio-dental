<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditDoctor extends EditRecord
{
    protected static string $resource = DoctorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Con citas o historias no se elimina: el botón explica que se apaga "Activo".
            DeleteAction::make()
                ->authorizationNotification()
                ->modalDescription('Deja de aparecer en la agenda y en la web. Se puede recuperar.'),
            RestoreAction::make(),
        ];
    }
}
