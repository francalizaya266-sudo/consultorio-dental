<?php

namespace App\Filament\Resources\WaitlistEntries\Pages;

use App\Enums\WaitlistStatus;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryActions;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryResource;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ManageWaitlistEntries extends ManageRecords
{
    protected static string $resource = WaitlistEntryResource::class;

    protected ?string $subheading = 'Quienes quieren un horario antes o un día que hoy está lleno. Cuando se libera uno que les sirve, llega la alerta para ofrecérselo por WhatsApp.';

    protected function getHeaderActions(): array
    {
        return [WaitlistEntryActions::add()];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('En espera')
                ->badge(fn () => WaitlistEntryResource::getEloquentQuery()->active()->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->active()),
            'booked' => Tab::make('Agendados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', WaitlistStatus::Booked)->reorder()->latest('updated_at')),
            'closed' => Tab::make('Retirados o vencidos')
                ->modifyQueryUsing(fn (Builder $query) => $query->closed()->reorder()->latest('updated_at')),
        ];
    }
}
