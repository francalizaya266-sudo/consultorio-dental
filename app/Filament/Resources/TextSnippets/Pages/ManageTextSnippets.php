<?php

namespace App\Filament\Resources\TextSnippets\Pages;

use App\Filament\Resources\TextSnippets\TextSnippetResource;
use App\Filament\Support\TextsMap;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

class ManageTextSnippets extends ManageRecords
{
    protected static string $resource = TextSnippetResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        return TextsMap::subheading('Frases listas para la historia clínica. En cada campo aparecen primero las que más usas.', 'snippets');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva opción')
                ->successNotificationTitle('Opción rápida guardada')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data) => TextSnippetResource::withOwnership($data)),
        ];
    }
}
