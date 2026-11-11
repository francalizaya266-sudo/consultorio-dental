<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Resources\Doctors\DoctorResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDoctors extends ListRecords
{
    use HasNoBreadcrumbs;

    protected static string $resource = DoctorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo doctor'),
        ];
    }
}
