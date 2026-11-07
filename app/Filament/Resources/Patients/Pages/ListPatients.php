<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Resources\Patients\PatientResource;
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

    /**
     * Cada pestaña cuenta con la misma consulta con nombre que usa "Hoy", así los números coinciden.
     * Quién debe está en Caja › Saldos pendientes, donde se cobra.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos'),
            'checkup' => Tab::make('Les toca control')
                ->badge(fn () => PatientResource::getEloquentQuery()->dueForCheckup()->count() ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->dueForCheckup()),
            'unscheduled' => Tab::make('Plan sin agendar')
                ->badge(fn () => PatientResource::getEloquentQuery()->withUnscheduledPlan()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->withUnscheduledPlan()),
            'proposed' => Tab::make('Presupuesto sin respuesta')
                ->badge(fn () => PatientResource::getEloquentQuery()->withPlanAwaitingAnswer()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->withPlanAwaitingAnswer()),
        ];
    }
}
