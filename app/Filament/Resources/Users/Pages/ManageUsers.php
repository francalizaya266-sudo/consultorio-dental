<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\UserRole;
use App\Filament\Resources\Doctors\DoctorResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;

class ManageUsers extends ManageRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo usuario')
                ->createAnother(false)
                ->after(function (User $record) {
                    if ($record->role === UserRole::Doctor) {
                        Notification::make()
                            ->info()
                            ->title('Falta su perfil de doctor')
                            ->body('Créalo en Doctores para darle su horario y tratamientos.')
                            ->actions([Action::make('doctor')->label('Crear perfil')->url(DoctorResource::getUrl('create', ['user' => $record->getKey()]))])
                            ->persistent()
                            ->send();
                    }
                }),
        ];
    }
}
