<?php

namespace App\Filament\Pages;

use App\Filament\Actions\CreateAppointmentAction;
use App\Filament\Resources\Appointments\AppointmentResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\WaitlistEntries\WaitlistEntryResource;
use App\Filament\Widgets\FollowUpsToday;
use App\Filament\Widgets\MonthOverview;
use App\Filament\Widgets\MonthTrend;
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
 * El inicio como dashboard: todo lo del día en la primera pantalla. Arriba, los números del día;
 * al centro, la agenda de hoy (y, para el doctor, su próximo paciente; para la administración, el
 * mes con su gráfico); al costado, la sala de espera, lo que hay que hacer y los huecos de la semana.
 * Cada tarjeta decide si se muestra con el mismo permiso de la pantalla a la que lleva. Si a alguien
 * no le toca nada del costado, el centro ocupa todo el ancho.
 */
class Dashboard extends BaseDashboard
{
    /** @var list<class-string> Arriba, a todo el ancho. */
    private const TOP = [SystemHealth::class, TodayStats::class];

    /** @var list<class-string> La columna ancha. */
    private const MAIN = [NextPatient::class, TodayAppointments::class, MonthOverview::class, MonthTrend::class];

    /** @var list<class-string> La columna angosta. */
    private const SIDE = [WaitingRoom::class, FollowUpsToday::class, WeekGaps::class];

    protected static ?string $title = 'Hoy';

    protected static ?string $navigationLabel = 'Hoy';

    public function getSubheading(): string
    {
        return ucfirst(today()->translatedFormat('l j \d\e F'));
    }

    /** @return list<class-string> */
    public function getWidgets(): array
    {
        return [...self::TOP, ...self::MAIN, ...self::SIDE];
    }

    public function content(Schema $schema): Schema
    {
        $side = $this->getWidgetsSchemaComponents(self::SIDE);

        return $schema->components([
            Group::make($this->getWidgetsSchemaComponents(self::TOP)),
            Grid::make(['default' => 1, 'lg' => 3])
                ->schema(array_values(array_filter([
                    Group::make($this->getWidgetsSchemaComponents(self::MAIN))
                        ->columnSpan(['default' => 1, 'lg' => $side === [] ? 3 : 2]),
                    $side === [] ? null : Group::make($side)->columnSpan(['default' => 1, 'lg' => 1]),
                ]))),
        ]);
    }

    /** Lo que más se hace al llegar alguien, a un clic desde el inicio. */
    protected function getHeaderActions(): array
    {
        return [
            CreateAppointmentAction::make('newAppointment')
                ->visible(fn () => AppointmentResource::canCreate()),
            Action::make('registerPayment')
                ->label('Registrar pago')
                ->icon(Heroicon::OutlinedBanknotes)
                ->color('gray')
                ->url(fn () => PaymentResource::getUrl('index', ['action' => 'create']))
                ->visible(fn () => PaymentResource::canCreate()),
            Action::make('waitlist')
                ->label('Anotar en espera')
                ->icon(Heroicon::OutlinedQueueList)
                ->color('gray')
                ->url(fn () => WaitlistEntryResource::getUrl('index', ['action' => 'create']))
                ->visible(fn () => WaitlistEntryResource::canCreate()),
        ];
    }
}
