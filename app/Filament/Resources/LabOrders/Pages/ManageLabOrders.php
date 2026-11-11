<?php

namespace App\Filament\Resources\LabOrders\Pages;

use App\Filament\Resources\LabOrders\LabOrderResource;
use App\Models\LabOrder;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageLabOrders extends ManageRecords
{
    protected static string $resource = LabOrderResource::class;

    public function getSubheading(): string
    {
        return 'Lo que salió al laboratorio dental: cuándo llega, y si la cita del paciente quedó antes de la entrega.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Anotar trabajo')
                ->modalHeading('Trabajo enviado al laboratorio')
                ->createAnother(false)
                ->mutateDataUsing(fn (array $data) => [...$data, 'created_by_user_id' => auth()->id()])
                ->successNotification(fn (LabOrder $record) => ($alert = $record->alert()) === null
                    ? Notification::make()->success()->title('Trabajo anotado')
                    : Notification::make()->warning()->title('Trabajo anotado')->body($alert.'.')->persistent()),
        ];
    }

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('En el laboratorio')
                ->badge(fn () => LabOrderResource::getEloquentQuery()->pending()->count() ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->pending()),
            'attention' => Tab::make('Por resolver')
                ->badge(fn () => LabOrderResource::getEloquentQuery()->needingAttention()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->needingAttention()),
            'received' => Tab::make('Recibidos')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereNotNull('received_on')->reorder()->latest('received_on')),
            'all' => Tab::make('Todos')
                ->modifyQueryUsing(fn (Builder $query) => $query->reorder()->latest('sent_on')),
        ];
    }
}
