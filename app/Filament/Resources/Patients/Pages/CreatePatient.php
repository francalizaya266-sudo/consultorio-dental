<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Services\Patients\PatientRegistrar;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreatePatient extends CreateRecord
{
    protected static string $resource = PatientResource::class;

    protected static ?string $title = 'Nuevo paciente';

    protected static ?string $breadcrumb = 'Nuevo';

    /** El celular agrupa a la familia: si ya existe, el paciente se suma a esa cuenta. */
    protected function handleRecordCreation(array $data): Model
    {
        return app(PatientRegistrar::class)->register(array_filter($data, fn ($value) => $value !== null && $value !== ''));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
