<?php

namespace App\Filament\Resources\Appointments\Pages;

use App\Enums\AppointmentStatus;
use App\Filament\Actions\CreateAppointmentAction;
use App\Filament\Concerns\HasNoBreadcrumbs;
use App\Filament\Concerns\RefreshesWithToday;
use App\Filament\Resources\Appointments\AppointmentResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * Citas: para buscar una cita de cualquier fecha, confirmar las que faltan y cerrar las que quedaron abiertas.
 * El día a día (hoy, mañana) se ve en la Agenda.
 */
class ListAppointments extends ListRecords
{
    use HasNoBreadcrumbs, RefreshesWithToday;

    protected static string $resource = AppointmentResource::class;

    /** @var array{total: int, toCall: int}|null Las citas por confirmar, contadas una vez por pedido. */
    private ?array $awaitingConfirmation = null;

    /** "Nueva cita" es la misma ventana de la agenda, sin salir de la lista. */
    protected function getHeaderActions(): array
    {
        return [
            CreateAppointmentAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            // Cada cita pendiente o confirmada está en un solo lugar: aquí mientras no pasa su hora, después en "Sin cerrar".
            'upcoming' => Tab::make('Próximas')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', AppointmentStatus::changeable())->where('ends_at', '>=', now())),
            // El número es el de la lista. Se pinta de alerta cuando alguna ya toca llamar (está a un día de su cita).
            'pending' => Tab::make('Por confirmar')
                ->badge(fn () => $this->awaitingConfirmation()['total'] ?: null)
                ->badgeColor(fn () => $this->awaitingConfirmation()['toCall'] > 0 ? 'warning' : 'gray')
                ->badgeTooltip(fn () => match ($toCall = $this->awaitingConfirmation()['toCall']) {
                    0 => 'Citas que todavía no confirman',
                    1 => '1 dice "Llamar": está a un día de su cita',
                    default => $toCall.' dicen "Llamar": están a un día de su cita',
                })
                ->modifyQueryUsing(fn (Builder $query) => $query->awaitingConfirmation()),
            'unclosed' => Tab::make('Sin cerrar')
                ->badgeColor('warning')
                ->badge(fn () => AppointmentResource::getEloquentQuery()->unclosed()->count() ?: null)
                ->badgeTooltip('Ya pasó su hora: marca si vino o si no asistió')
                ->modifyQueryUsing(fn (Builder $query) => $query->unclosed()),
            'all' => Tab::make('Todas'),
        ];
    }

    /** Un enlace guardado a una pestaña que ya no existe ("Hoy", "Mañana") abre la primera. */
    protected function loadDefaultActiveTab(): void
    {
        if (! array_key_exists((string) $this->activeTab, $this->getCachedTabs())) {
            $this->activeTab = $this->getDefaultActiveTab();
        }
    }

    /** @return array{total: int, toCall: int} */
    private function awaitingConfirmation(): array
    {
        if ($this->awaitingConfirmation === null) {
            $counts = AppointmentResource::getEloquentQuery()
                ->awaitingConfirmation()
                ->toBase()
                ->selectRaw('count(*) as total, count(unconfirmed_flagged_at) as to_call')
                ->first();

            $this->awaitingConfirmation = ['total' => (int) $counts->total, 'toCall' => (int) $counts->to_call];
        }

        return $this->awaitingConfirmation;
    }
}
