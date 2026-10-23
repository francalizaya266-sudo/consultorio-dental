<?php

namespace App\Filament\Resources\MessageTemplates\Pages;

use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use Filament\Resources\Pages\ManageRecords;

class ManageMessageTemplates extends ManageRecords
{
    protected static string $resource = MessageTemplateResource::class;

    public function getSubheading(): ?string
    {
        return 'Así se escriben los avisos que el sistema prepara. Las palabras entre llaves se reemplazan solas.';
    }
}
