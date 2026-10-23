<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListAppointments extends ListRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nueva cita'),
        ];
    }

    public function getTabs(): array
    {
        return [
            'today' => Tab::make('Hoy')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('starts_at', today())),
            'tomorrow' => Tab::make('Mañana')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereDate('starts_at', today()->addDay())),
            'upcoming' => Tab::make('Próximas')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('starts_at', '>=', today())->whereIn('status', AppointmentStatus::changeable())),
            'pending' => Tab::make('Por confirmar')
                ->badgeColor('danger')
                ->badge(fn () => AppointmentResource::getEloquentQuery()->where('status', AppointmentStatus::Pending)->whereNotNull('unconfirmed_flagged_at')->where('starts_at', '>=', now())->count() ?: null)
                ->badgeTooltip('Sin confirmar a un día de la cita: conviene llamar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AppointmentStatus::Pending)->where('starts_at', '>=', now())),
            'all' => Tab::make('Todas'),
        ];
    }
}
