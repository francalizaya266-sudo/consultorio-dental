<?php

namespace App\Filament\Resources\Patients\Pages;

use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListPatients extends ListRecords
{
    use HasNoBreadcrumbs;

    protected static string $resource = PatientResource::class;

    /** Para qué es esta lista y dónde está lo demás: lo que se le propuso a cada uno se sigue en Presupuestos y planes. */
    public function getSubheading(): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        return collect([
            $user->managesAllAgendas()
                ? 'Todas las personas que se atienden aquí. Toca una para abrir su ficha: datos, citas, pagos y familia.'
                : 'Las personas que atiendes. Toca una para abrir su ficha y, desde ahí, su historia clínica.',
            TreatmentPlanResource::canViewAny() ? 'Sus presupuestos se siguen en Presupuestos y planes.' : null,
        ])->filter()->implode(' ');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('Nuevo paciente'),
        ];
    }

    /**
     * Cada pestaña cuenta con la misma consulta con nombre que usa "Hoy", así los números coinciden.
     * Quién debe está en Caja › Saldos pendientes, donde se cobra; el presupuesto sin respuesta y el plan
     * sin agendar, en Presupuestos y planes, donde se les escribe y se les agenda.
     */
    public function getTabs(): array
    {
        return [
            'all' => Tab::make('Todos'),
            'checkup' => Tab::make('Les toca control')
                ->badge(fn () => PatientResource::getEloquentQuery()->dueForCheckup()->count() ?: null)
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query) => $query->dueForCheckup()),
            'missed' => Tab::make('Faltaron o cancelaron')
                ->badge(fn () => PatientResource::getEloquentQuery()->missedAndNotRebooked()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->missedAndNotRebooked()),
        ];
    }
}
