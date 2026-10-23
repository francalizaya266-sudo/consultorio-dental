<?php

namespace App\Filament\Resources\ConsentTemplates\Pages;

use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use Filament\Resources\Pages\ManageRecords;

class ManageConsentTemplates extends ManageRecords
{
    protected static string $resource = ConsentTemplateResource::class;

    public function getSubheading(): ?string
    {
        return 'Así se arma el consentimiento que firma el paciente. Las palabras entre llaves se reemplazan con sus datos.';
    }
}
