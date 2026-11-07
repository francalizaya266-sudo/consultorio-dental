<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Users\UserResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newDoctor')
                ->label('Nuevo doctor con acceso')
                ->color('gray')
                ->visible(fn () => DoctorResource::canCreate())
                ->url(fn () => DoctorResource::getUrl('create')),
            CreateAction::make()
                ->label('Nuevo usuario')
                ->modalDescription('Para administración o recepción. Un doctor se crea en Doctores, junto con su acceso.')
                ->createAnother(false),
        ];
    }
}
