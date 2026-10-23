<?php

namespace App\Filament\Resources\RescheduleRequests\Pages;

use App\Enums\RescheduleRequestStatus;
use App\Filament\Resources\RescheduleRequests\RescheduleRequestResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListRescheduleRequests extends ListRecords
{
    protected static string $resource = RescheduleRequestResource::class;

    public function getTabs(): array
    {
        return [
            'pending' => Tab::make('Pendientes')
                ->badge(fn () => RescheduleRequestResource::getEloquentQuery()->where('status', RescheduleRequestStatus::Pending)->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', RescheduleRequestStatus::Pending)),
            'resolved' => Tab::make('Resueltas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', '!=', RescheduleRequestStatus::Pending)),
        ];
    }
}
