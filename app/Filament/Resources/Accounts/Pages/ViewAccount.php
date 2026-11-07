<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountActions;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewAccount extends ViewRecord
{
    protected static string $resource = AccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            AccountActions::sendAccessLink(),
            AccountActions::unblock(),
            EditAction::make()->color('gray'),
            // Bloquear va en el menú ⋮, no como botón rojo: se usa poco y el rojo queda para lo urgente.
            ActionGroup::make([AccountActions::block()]),
        ];
    }
}
