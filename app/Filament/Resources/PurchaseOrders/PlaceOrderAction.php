<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Exceptions\InventoryException;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Supply;
use App\Services\Inventory\InventoryService;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/**
 * "Pedir lo que falta": se elige el proveedor y ya aparece lo que está bajo el mínimo, con la
 * cantidad sugerida. Al confirmar se abre su WhatsApp con el pedido escrito.
 */
final class PlaceOrderAction
{
    public static function make(): Action
    {
        return Action::make('placeOrder')
            ->label('Pedir lo que falta')
            ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
            ->color('success')
            ->visible(fn () => auth()->user()->can('create', PurchaseOrder::class))
            ->modalHeading('Pedir lo que falta')
            ->modalDescription('Revisa las cantidades. Se abrirá el WhatsApp del proveedor con el pedido escrito: solo presiona Enviar.')
            ->modalSubmitActionLabel('Abrir WhatsApp')
            ->fillForm(function () {
                $supplierId = self::supplierOptions() === [] ? null : array_key_first(self::supplierOptions());

                return ['supplier_id' => $supplierId, 'items' => self::suggested($supplierId)];
            })
            ->schema([
                Select::make('supplier_id')
                    ->label('Proveedor')
                    ->options(fn () => self::supplierOptions() + Supplier::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn (Set $set, $state) => $set('items', self::suggested($state))),
                Repeater::make('items')
                    ->label('Materiales')
                    ->table([
                        TableColumn::make('Material'),
                        TableColumn::make('Cantidad')->width('11rem'),
                    ])
                    ->schema([
                        Select::make('supply_id')
                            ->label('Material')
                            ->options(fn () => Supply::query()->active()->orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->required()
                            ->live(),
                        TextInput::make('quantity')
                            ->label('Cantidad')
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->live(onBlur: true)
                            ->suffix(fn (Get $get) => Supply::unitFor($get('supply_id'), $get('quantity'))),
                    ])
                    ->defaultItems(0)
                    ->minItems(1)
                    ->reorderable(false)
                    ->addActionLabel('Agregar otro material'),
                Textarea::make('notes')
                    ->label('Nota para el proveedor')
                    ->placeholder('Ej.: entregar el jueves por la mañana')
                    ->rows(2),
            ])
            ->action(function (array $data, Component $livewire) {
                $supplier = Supplier::query()->findOrFail($data['supplier_id']);

                try {
                    $order = app(InventoryService::class)->placeOrder($supplier, $data['items'] ?? [], auth()->user(), $data['notes'] ?? null);
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                $url = app(InventoryService::class)->whatsAppUrl($order);

                if ($url) {
                    $livewire->js('window.open('.json_encode($url, JSON_UNESCAPED_SLASHES).', "_blank")');
                    Notification::make()->success()->title('Pedido listo. Cuando llegue, regístralo con "Recibir".')->send();

                    return;
                }

                Notification::make()
                    ->warning()
                    ->title($supplier->name.' no tiene WhatsApp registrado')
                    ->body('El pedido quedó guardado. Agrégale un celular en Proveedores, o envíale este texto: '.app(InventoryService::class)->orderMessage($order))
                    ->persistent()
                    ->send();
            });
    }

    /** @return array<int, string> Proveedores con algo por reponer primero: "Dental Perú (3 por reponer)". */
    private static function supplierOptions(): array
    {
        return Supplier::query()
            ->active()
            ->withCount(['supplies as low_count' => fn (Builder $supplies) => $supplies->toReorder()])
            ->orderByDesc('low_count')
            ->orderBy('name')
            ->get()
            ->filter(fn (Supplier $supplier) => $supplier->low_count > 0)
            ->mapWithKeys(fn (Supplier $supplier) => [$supplier->getKey() => $supplier->name.' ('.$supplier->low_count.' por reponer)'])
            ->all();
    }

    /** @return list<array{supply_id: int, quantity: float}> */
    private static function suggested(mixed $supplierId): array
    {
        $supplier = filled($supplierId) ? Supplier::query()->find($supplierId) : null;

        if ($supplier === null) {
            return [];
        }

        return app(InventoryService::class)->toReorder($supplier)
            ->map(fn (Supply $supply) => ['supply_id' => $supply->getKey(), 'quantity' => $supply->suggestedOrder()])
            ->values()
            ->all();
    }
}
