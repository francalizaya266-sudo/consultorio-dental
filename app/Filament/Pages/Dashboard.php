<?php

namespace App\Filament\Pages;

use App\Filament\Actions\CreateAppointmentAction;
use App\Filament\Actions\RegisterPaymentAction;
use App\Filament\Actions\WalkInAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryActions;
use App\Filament\Widgets\DayMomentCard;
use App\Filament\Widgets\FollowUpsToday;
use App\Filament\Widgets\MonthOverview;
use App\Filament\Widgets\NextPatient;
use App\Filament\Widgets\SystemHealth;
use App\Filament\Widgets\TodayAppointments;
use App\Filament\Widgets\TodayStats;
use App\Filament\Widgets\WaitingRoom;
use App\Filament\Widgets\WeekGaps;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * El inicio como dashboard: todo lo del día en la primera pantalla. Arriba, los números del día y lo que toca
 * según la hora (antes de abrir, confirmar; al terminar, cerrar el día);
 * al centro, la agenda de hoy (y, para el doctor, su próximo paciente); al costado, la sala de espera,
 * lo que hay que hacer y los huecos de la semana; y al final, cómo va el mes. En el celular va en ese
 * mismo orden: primero lo del día, lo del mes al último. Cada tarjeta decide si se muestra con el
 * mismo permiso de la pantalla a la que lleva. Si a alguien no le toca nada del costado, el centro
 * ocupa todo el ancho.
 */
class Dashboard extends BaseDashboard
{
    /** El aviso con que una tarjeta dice a las demás que algo cambió (RefreshesWithToday). */
    public const CHANGED = 'today-changed';

    /** @var list<class-string> Arriba, a todo el ancho. */
    private const TOP = [SystemHealth::class, TodayStats::class, DayMomentCard::class];

    /** @var list<class-string> La columna ancha: lo de hoy. */
    private const MAIN = [NextPatient::class, TodayAppointments::class];

    /** @var list<class-string> La columna angosta. */
    private const SIDE = [WaitingRoom::class, FollowUpsToday::class, WeekGaps::class];

    /** @var list<class-string> Debajo de la columna ancha (en el celular, al final de todo). */
    private const MONTH = [MonthOverview::class];

    protected static ?string $title = 'Hoy';

    protected static ?string $navigationLabel = 'Hoy';

    public function getSubheading(): string
    {
        return ucfirst(today()->translatedFormat('l j \d\e F'));
    }

    /** @return list<class-string> */
    public function getWidgets(): array
    {
        return [...self::TOP, ...self::MAIN, ...self::SIDE, ...self::MONTH];
    }

    /**
     * Tres bloques en el orden en que se leen en el celular: hoy, el costado y el mes. En pantalla
     * ancha el costado ocupa las dos filas (panel.css, .dash-side) y el mes queda bajo la agenda.
     */
    public function content(Schema $schema): Schema
    {
        $side = $this->getWidgetsSchemaComponents(self::SIDE);
        $month = $this->getWidgetsSchemaComponents(self::MONTH);
        $wide = ['default' => 1, 'lg' => $side === [] ? 3 : 2];

        return $schema->components([
            Group::make($this->getWidgetsSchemaComponents(self::TOP)),
            Grid::make(['default' => 1, 'lg' => 3])
                ->extraAttributes(['class' => 'dash-layout'])
                ->schema(array_values(array_filter([
                    Group::make($this->getWidgetsSchemaComponents(self::MAIN))->columnSpan($wide),
                    $side === [] ? null : Group::make($side)
                        ->columnSpan(['default' => 1, 'lg' => 1])
                        ->extraAttributes(['class' => 'dash-side']),
                    $month === [] ? null : Group::make($month)->columnSpan($wide),
                ]))),
        ]);
    }

    /**
     * Lo que más se hace al llegar o llamar alguien, a un clic desde el inicio y sin salir de él. Al pasar el
     * mouse, cada botón dice para qué es: "Llegó sin cita" lo pasa a la sala de hoy; la lista de espera es para
     * quien quiere un horario que todavía no hay.
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAppointmentAction::make('newAppointment')
                ->tooltip('Agenda una cita para hoy o para otro día. El aviso al paciente queda listo en Avisos.')
                ->visible(fn () => AppointmentResource::canCreate()),
            WalkInAction::make()
                ->tooltip('Está en el mostrador y no tiene cita: se le da el primer hueco de hoy y pasa a la sala de espera.')
                ->visible(fn () => AppointmentResource::canCreate()),
            RegisterPaymentAction::make()
                ->color('gray')
                ->tooltip('Registra el pago de cualquier paciente, aunque hoy no tenga cita, y deja lista su constancia.'),
            WaitlistEntryActions::add('waitlist')
                ->label('Anotar en lista de espera')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->tooltip('Quiere un horario antes o un día que está lleno: te llega la alerta cuando se libere uno que le sirva.'),
        ];
    }

    /** Una cita nueva, alguien que llegó o un pago cambian la agenda, la sala y los números: se actualizan al momento. */
    protected function afterActionCalled(Action $action): void
    {
        $this->dispatch(self::CHANGED);
    }
}
