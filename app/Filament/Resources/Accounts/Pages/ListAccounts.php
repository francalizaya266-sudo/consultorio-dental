<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    protected static string $resource = AccountResource::class;

    protected static ?string $title = 'Celulares y familias';

    public function getSubheading(): ?string
    {
        return 'Cada celular agrupa a su familia. Los celulares se crean solos al registrar un paciente.';
    }
}
