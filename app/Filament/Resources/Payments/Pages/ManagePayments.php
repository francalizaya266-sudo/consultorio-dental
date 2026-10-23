<?php

namespace App\Filament\Resources\Payments\Pages;

use App\Filament\Resources\Payments\PaymentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePayments extends ManageRecords
{
    protected static string $resource = PaymentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Registrar pago')
                ->modalHeading('Registrar pago')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data) => $data + ['received_by_user_id' => auth()->id()]),
        ];
    }
}
