<?php

namespace App\Filament\Resources\Supplies\Pages;

use App\Filament\Pages\StockCount;
use App\Filament\Pages\TomorrowTray;
use App\Filament\Resources\PurchaseOrders\PlaceOrderAction;
use App\Filament\Resources\StockMovements\StockMovementResource;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\PurchaseOrder;
use App\Models\Supply;
use App\Services\Inventory\StarterCatalog;
use App\Services\Inventory\SupplyForecast;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ManageSupplies extends ManageRecords
{
    protected static string $resource = SupplyResource::class;

    /** El mismo nombre que en el menú. */
    protected static ?string $title = 'Inventario';

    public function getSubheading(): ?string
    {
        return 'Cada atención descuenta lo que usa su tratamiento. Si algo baja del mínimo o está por vencer, te avisa.';
    }

    protected function getHeaderActions(): array
    {
        return [
            PlaceOrderAction::make()
                ->visible(fn () => auth()->user()->can('create', PurchaseOrder::class) && Supply::query()->toReorder()->exists()),
            Action::make('tray')
                ->label('Bandeja de mañana')
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->url(fn () => TomorrowTray::getUrl()),
            Action::make('count')
                ->label('Contar')
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('gray')
                ->visible(fn () => StockCount::canAccess() && Supply::query()->exists())
                ->url(fn () => StockCount::getUrl()),
            // Para rastrear un lote o una serie (un implante retirado, un lote con alerta): todos los movimientos.
            Action::make('movements')
                ->label('Buscar lote o serie')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('gray')
                ->visible(fn () => StockMovementResource::canViewAny() && Supply::query()->exists())
                ->url(fn () => StockMovementResource::getUrl('index')),
            $this->starterAction(),
            CreateAction::make()
                ->label('Nuevo material')
                ->createAnother(false),
        ];
    }

    public function getTabs(): array
    {
        return [
            'active' => Tab::make('En uso')
                ->modifyQueryUsing(fn (Builder $query) => $query->active()),
            // Bajo el mínimo y sin pedir todavía: lo mismo que cuentan el menú y Hoy.
            'low' => Tab::make('Por pedir')
                ->badge(fn () => Supply::query()->toReorder()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->toReorder()),
            // No están bajo el mínimo todavía, pero con las citas agendadas se acaban o bajan en dos semanas.
            'soon' => Tab::make('Se acaban pronto')
                ->badge(fn () => app(SupplyForecast::class)->runningLow()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->whereKey(app(SupplyForecast::class)->runningLow()->keys())),
            'expiring' => Tab::make('Por vencer')
                ->badge(fn () => Supply::query()->expiringSoon()->count() ?: null)
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query) => $query->expiringSoon()->reorder('next_expiry')),
            'inactive' => Tab::make('Ya no se usan')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('is_active', false)),
        ];
    }

    /** Para empezar sin escribir todo: los materiales de siempre y lo que usa cada tratamiento. */
    private function starterAction(): Action
    {
        return Action::make('starter')
            ->label('Usar la lista sugerida')
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->visible(fn () => auth()->user()->can('create', Supply::class) && ! Supply::query()->exists())
            ->requiresConfirmation()
            ->modalIcon(Heroicon::OutlinedSparkles)
            ->modalHeading('Empezar con la lista sugerida')
            ->modalDescription(count(StarterCatalog::SUPPLIES).' materiales de uso diario (guantes, anestesia, resinas, implantes…) y lo que usa cada tratamiento. Después cuentas lo que tienes y ajustas lo que haga falta.')
            ->modalSubmitActionLabel('Agregar la lista')
            ->action(function () {
                ['supplies' => $supplies, 'recipes' => $recipes] = app(StarterCatalog::class)->install();

                Notification::make()
                    ->success()
                    ->title("Se agregaron {$supplies} materiales y la receta de {$recipes} tratamientos.")
                    ->body('Ahora cuenta lo que tienes: así el inventario empieza con lo que de verdad hay.')
                    ->actions([Action::make('count')->label('Ir a Conteo')->button()->url(StockCount::getUrl())])
                    ->persistent()
                    ->send();
            });
    }
}
