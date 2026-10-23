<?php

namespace App\Filament\Resources\Doctors\Pages;

use App\Filament\Resources\Doctors\DoctorResource;
use Filament\Resources\Pages\CreateRecord;

class CreateDoctor extends CreateRecord
{
    protected static string $resource = DoctorResource::class;

    protected static ?string $title = 'Nuevo doctor';

    protected static ?string $breadcrumb = 'Nuevo';

    protected static bool $canCreateAnother = false;

    /** Al crear, se sigue en la ficha para agregar sus tratamientos. */
    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
