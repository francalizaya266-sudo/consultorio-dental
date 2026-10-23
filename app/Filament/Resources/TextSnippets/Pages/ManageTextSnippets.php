<?php

namespace App\Filament\Resources\TextSnippets\Pages;

use App\Filament\Resources\TextSnippets\TextSnippetResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageTextSnippets extends ManageRecords
{
    protected static string $resource = TextSnippetResource::class;

    public function getSubheading(): ?string
    {
        return 'Frases listas para la historia clínica. En cada campo aparecen primero las que más usas.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Nueva opción')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data) => TextSnippetResource::withOwnership($data)),
        ];
    }
}
