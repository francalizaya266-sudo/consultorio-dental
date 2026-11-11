<?php

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\ListRecords;

class ListAccounts extends ListRecords
{
    use HasNoBreadcrumbs;

    protected static string $resource = AccountResource::class;

    protected static ?string $title = 'Celulares y familias';

    public function getSubheading(): ?string
    {
        return 'Cada celular agrupa a su familia. Los celulares se crean solos al registrar un paciente.';
    }
}
