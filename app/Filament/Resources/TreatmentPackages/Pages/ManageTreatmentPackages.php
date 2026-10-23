<?php

namespace App\Filament\Resources\TreatmentPackages\Pages;

use App\Filament\Resources\TreatmentPackages\TreatmentPackageResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Enums\Width;

class ManageTreatmentPackages extends ManageRecords
{
    protected static string $resource = TreatmentPackageResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo paquete')
                ->modalHeading('Nuevo paquete')
                ->modalWidth(Width::FourExtraLarge)
                ->successNotificationTitle('Paquete guardado. Ya se puede agregar a un plan.')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data) => [...$data, 'created_by_user_id' => auth()->id()]),
        ];
    }
}
