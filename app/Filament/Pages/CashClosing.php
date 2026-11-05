<?php

namespace App\Filament\Pages;

use App\Filament\Widgets\CashClosingStats;
use App\Filament\Widgets\CashClosingTables;
use App\Models\Payment;
use App\Services\Billing\CashClosing as Closing;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * El cuadre de recepción al cerrar el día: lo cobrado por medio de pago y por persona, los pagos
 * uno por uno y lo anulado, para contar el efectivo e imprimir la hoja firmada.
 */
class CashClosing extends BaseDashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'cierre-de-caja';

    protected static ?string $title = 'Cierre de caja';

    protected static ?string $navigationLabel = 'Cierre del día';

    protected static ?string $navigationParentItem = 'Caja';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('viewAny', Payment::class) ?? false;
    }

    /** Cada vez que se entra es para cerrar hoy: no se recuerda el día que se vio antes. */
    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    public function getWidgets(): array
    {
        return [CashClosingStats::class, CashClosingTables::class];
    }

    public function getColumns(): int|array
    {
        return 1;
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columnSpanFull()
                ->columns(['sm' => 2, 'lg' => 4])
                ->schema([
                    DatePicker::make('date')
                        ->label('Día')
                        ->native(false)
                        ->displayFormat('D d/m/Y')
                        ->firstDayOfWeek(1)
                        ->closeOnDateSelection()
                        ->default(today()->toDateString())
                        ->maxDate(today()),
                    Select::make('person')
                        ->label('Recibió')
                        ->placeholder('Todos')
                        ->options(fn (Get $get) => Closing::receivers(Closing::fromFilters(['date' => $get('date')])->date)),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')
                ->label('Imprimir o PDF')
                ->icon(Heroicon::OutlinedPrinter)
                ->url(fn () => Filament::getPanel('admin')->route('cash-closing.print', $this->closingQuery()), shouldOpenInNewTab: true),
        ];
    }

    /** @return array<string, mixed> El día y la persona, para la hoja de imprimir. */
    private function closingQuery(): array
    {
        $closing = Closing::fromFilters($this->filters ?? []);

        return array_filter([
            'date' => $closing->date->toDateString(),
            'person' => $closing->receivedBy,
        ], fn ($value) => filled($value));
    }
}
