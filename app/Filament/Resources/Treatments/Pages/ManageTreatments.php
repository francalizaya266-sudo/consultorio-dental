<?php

namespace App\Filament\Resources\Treatments\Pages;

use App\Filament\Resources\Treatments\TreatmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTreatments extends ManageRecords
{
    protected static string $resource = TreatmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo tratamiento')
                ->mutateDataUsing(fn (array $data) => TreatmentResource::withPresence($data))
                ->successNotificationTitle('Tratamiento guardado')
                // "Guardar" y "Cancelar", como en las demás ventanas de Configuración: aquí además salía "Crear y crear otro".
                ->createAnother(false),
        ];
    }
}
