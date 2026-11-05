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
                ->badgeColor('warning')
                ->badge(fn () => AppointmentResource::getEloquentQuery()->where('status', AppointmentStatus::Pending)->whereNotNull('unconfirmed_flagged_at')->where('starts_at', '>=', now())->count() ?: null)
                ->badgeTooltip('Sin confirmar a un día de la cita: conviene llamar')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', AppointmentStatus::Pending)->where('starts_at', '>=', now())),
            'unclosed' => Tab::make('Sin cerrar')
                ->badgeColor('warning')
                ->badge(fn () => AppointmentResource::getEloquentQuery()->leftOpen()->count() ?: null)
                ->badgeTooltip('De días pasados: marca si vino o si no asistió')
                ->modifyQueryUsing(fn (Builder $query) => $query->leftOpen()),
            'all' => Tab::make('Todas'),
        ];
    }
}
