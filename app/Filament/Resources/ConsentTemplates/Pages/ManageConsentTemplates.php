<?php

namespace App\Filament\Resources\ConsentTemplates\Pages;

use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use App\Filament\Support\TextsMap;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageConsentTemplates extends ManageRecords
{
    protected static string $resource = ConsentTemplateResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return TextsMap::subheading('Así se arma el consentimiento que firma el paciente. Las palabras entre llaves se reemplazan con sus datos.', 'consents');
    }

    /** "Nuevo texto" arriba a la derecha, como en las demás secciones: antes iba dentro de la tabla. */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nuevo texto')
                ->successNotificationTitle('Texto de consentimiento guardado')
                ->createAnother(false),
        ];
    }
}
