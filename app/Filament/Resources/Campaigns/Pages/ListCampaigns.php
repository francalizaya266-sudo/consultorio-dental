<?php

namespace App\Filament\Resources\Campaigns\Pages;

use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Resources\Campaigns\CampaignResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCampaigns extends ListRecords
{
    use HasNoBreadcrumbs;

    protected static string $resource = CampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva campaña'),
        ];
    }
}
