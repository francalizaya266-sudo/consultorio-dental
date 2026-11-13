<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Un solo camino por tipo de persona: administración y recepción se crean aquí; un doctor, en Doctores,
            // con su ficha y su acceso. Este botón solo lleva allá.
            Action::make('newDoctor')
                ->label('Nuevo doctor (en Doctores)')
                ->color('gray')
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->visible(fn () => DoctorResource::canCreate())
                ->url(fn () => DoctorResource::getUrl('create')),
            CreateAction::make()
                ->label('Nuevo usuario')
                ->modalDescription('Para administración o recepción. Un doctor se crea en Doctores, junto con su acceso.')
                ->successNotificationTitle('Usuario guardado')
                ->createAnother(false),
        ];
    }
}
