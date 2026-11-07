<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditCampaign extends EditRecord
{
    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // En el menú ⋮, no como botón rojo: el rojo queda para lo urgente.
            ActionGroup::make([
                DeleteAction::make(),
                RestoreAction::make(),
            ]),
        ];
    }
}
