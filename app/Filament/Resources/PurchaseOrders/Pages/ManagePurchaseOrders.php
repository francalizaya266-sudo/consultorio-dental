<?php

namespace App\Filament\Resources\PurchaseOrders\Pages;

use App\Filament\Resources\PurchaseOrders\PlaceOrderAction;
use App\Filament\Resources\PurchaseOrders\PurchaseOrderResource;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Models\PurchaseOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;

class ManagePurchaseOrders extends ManageRecords
{
    protected static string $resource = PurchaseOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('suppliers')
                ->label('Proveedores')
                ->icon(Heroicon::OutlinedTruck)
                ->color('gray')
                ->visible(fn () => SupplierResource::canViewAny())
                ->url(fn () => SupplierResource::getUrl('index')),
            PlaceOrderAction::make(),
        ];
    }

    public function getTabs(): array
    {
        return [
            'open' => Tab::make('Por llegar')
                ->badge(fn () => PurchaseOrder::query()->open()->count() ?: null)
                ->modifyQueryUsing(fn (Builder $query) => $query->open()),
            'all' => Tab::make('Todos'),
        ];
    }
}
