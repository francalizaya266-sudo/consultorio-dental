<?php

namespace App\Filament\Resources\SmileCases\Pages;

use App\Filament\Resources\SmileCases\SmileCaseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSmileCases extends ManageRecords
{
    protected static string $resource = SmileCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                // Queda constancia de quién confirmó la autorización y cuándo.
                ->mutateDataUsing(fn (array $data) => $data + [
                    'consent_confirmed_at' => now(),
                    'consent_confirmed_by_user_id' => auth()->id(),
                ]),
        ];
    }
}
