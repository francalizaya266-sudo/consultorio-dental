<?php

namespace App\Filament\Resources\MessageTemplates\Pages;

use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Support\TextsMap;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageMessageTemplates extends ManageRecords
{
    protected static string $resource = MessageTemplateResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return TextsMap::subheading('Así se escriben los avisos que el sistema prepara. Las palabras entre llaves se reemplazan solas.', 'messages');
    }
}
