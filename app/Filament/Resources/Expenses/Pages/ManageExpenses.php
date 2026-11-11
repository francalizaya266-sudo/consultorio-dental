<?php

namespace App\Filament\Resources\Expenses\Pages;

use App\Filament\Resources\Expenses\ExpenseResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenses extends ManageRecords
{
    protected static string $resource = ExpenseResource::class;

    public function getSubheading(): string
    {
        return 'Lo que sale del consultorio. Lo pagado en efectivo se descuenta en el cierre del día.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Anotar gasto')
                ->modalHeading('Anotar gasto')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data) => [...$data, 'paid_by_user_id' => auth()->id()])
                ->successNotificationTitle('Gasto anotado'),
        ];
    }
}
