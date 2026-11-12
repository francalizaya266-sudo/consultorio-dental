<?php

namespace App\Filament\Resources\TreatmentPlans\Pages;

use App\Enums\TreatmentPlanStatus;
use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Resources\TreatmentPlans\TreatmentPlanResource;
use App\Models\User;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/** Las pestañas cuentan con las mismas consultas que "Por hacer hoy", así los números coinciden. */
class ListTreatmentPlans extends ListRecords
{
    use HasNoBreadcrumbs;

    protected static string $resource = TreatmentPlanResource::class;

    /** Para qué es esta lista: aquí no se arma nada, se le hace seguimiento a lo que ya se propuso. */
    public function getSubheading(): ?string
    {
        /** @var User $user */
        $user = auth()->user();

        return ($user->managesAllAgendas() ? 'Lo que se le propuso a cada paciente' : 'Lo que le propusiste a cada paciente')
            .' y en qué quedó: si respondió, si le falta cita y cuánto lleva pagado. Toca uno para abrirlo.';
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Vigentes')
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'awaiting' => Tab::make('Sin respuesta')
                ->badge(fn () => TreatmentPlanResource::getEloquentQuery()->awaitingAnswer()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingAnswer()->reorder()->oldest('proposed_at')),
            'unscheduled' => Tab::make('Sin próxima cita')
                ->badge(fn () => TreatmentPlanResource::getEloquentQuery()->unscheduled()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->unscheduled()),
            'completed' => Tab::make('Terminados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TreatmentPlanStatus::Completed)->reorder()->latest('completed_at')),
            'cancelled' => Tab::make('Cancelados')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', TreatmentPlanStatus::Cancelled)->reorder()->latest('updated_at')),
        ];
    }
}
