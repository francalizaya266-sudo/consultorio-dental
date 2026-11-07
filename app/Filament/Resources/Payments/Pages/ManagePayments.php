<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Resources\Payments\PaymentResource;
use Filament\Resources\Pages\ManageRecords;

class ManagePayments extends ManageRecords
{
    protected static string $resource = PaymentResource::class;

    /** El mismo nombre que en el menú. */
    protected static ?string $title = 'Caja';

    /** El mismo cobro de la ficha y de "Hoy": se elige al paciente y se ve lo que debe. */
    protected function getHeaderActions(): array
    {
        return [
            RegisterPaymentAction::make(),
        ];
    }
}
