<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
use App\Models\Charge;
use App\Models\Patient;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPatients extends ListRecords
{
    protected static string $resource = PatientResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo paciente'),
        ];
    }

    public function getTabs(): array
    {
        if (! auth()->user()->can('viewAny', Charge::class)) {
            return [];
        }

        return [
            'all' => Tab::make('Todos'),
            'owing' => Tab::make('Con saldo pendiente')
                ->badge(fn () => Patient::query()->owing()->count() ?: null)
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query) => $query->owing()),
        ];
    }
}
