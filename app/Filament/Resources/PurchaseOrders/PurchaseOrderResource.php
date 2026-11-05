<?php

namespace App\Filament\Resources\PurchaseOrders;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\InventoryException;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\PurchaseOrders\Pages\ManagePurchaseOrders;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supply;
use App\Services\Inventory\InventoryService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;

/** Compras: lo que se pidió a cada proveedor, por WhatsApp, y lo que ya llegó. */
class PurchaseOrderResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'compras';

    protected static ?string $model = PurchaseOrder::class;

    protected static ?string $modelLabel = 'pedido';

    protected static ?string $pluralModelLabel = 'compras';

    protected static ?string $navigationLabel = 'Compras';

    protected static ?string $navigationParentItem = 'Inventario';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplier', 'items.supply', 'createdBy']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('supplier.name')
                    ->label('Proveedor')
                    ->description(fn (PurchaseOrder $record) => 'Pedido #'.$record->getKey().' · '.$record->sent_at?->translatedFormat('j M Y'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('items')
                    ->label('Qué se pidió')
                    ->state(fn (PurchaseOrder $record) => $record->summary(3))
                    ->wrap(),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge()
                    ->description(fn (PurchaseOrder $record) => $record->received_at ? 'Llegó el '.$record->received_at->format('d/m/Y') : null),
                TextColumn::make('createdBy.name')
                    ->label('Lo pidió')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Estado')
                    ->options(PurchaseOrderStatus::class),
                SelectFilter::make('supplier_id')
                    ->label('Proveedor')
                    ->relationship('supplier', 'name'),
            ])
            ->recordActions([
                self::receiveAction(),
                Action::make('whatsapp')
                    ->label('WhatsApp')
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->color('gray')
                    ->visible(fn (PurchaseOrder $record) => $record->status->isOpen() && $record->supplier->hasWhatsApp())
                    ->url(fn (PurchaseOrder $record) => app(InventoryService::class)->whatsAppUrl($record), shouldOpenInNewTab: true),
                Action::make('cancel')
                    ->label('Anular')
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('gray')
                    ->visible(fn (PurchaseOrder $record) => auth()->user()->can('update', $record) && $record->items->every(fn (PurchaseOrderItem $item) => $item->received_quantity === null))
                    ->requiresConfirmation()
                    ->modalHeading('¿Anular el pedido?')
                    ->modalDescription('Queda como anulado. Si el proveedor ya lo envió, mejor regístralo con "Recibir".')
                    ->modalSubmitActionLabel('Sí, anular')
                    ->action(fn (PurchaseOrder $record) => app(InventoryService::class)->cancelOrder($record)),
            ])
            ->emptyStateHeading('Todavía no hay pedidos')
            ->emptyStateDescription('Con "Pedir lo que falta" se arma el pedido con lo que está bajo el mínimo y se envía por WhatsApp.');
    }

    /** Llegó el pedido: cada material con lo que llegó, su lote y vencimiento (los implantes, con su serie). */
    public static function receiveAction(): Action
    {
        return Action::make('receive')
            ->label('Recibir')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->visible(fn (PurchaseOrder $record) => auth()->user()->can('update', $record))
            ->modalHeading(fn (PurchaseOrder $record) => 'Recibir pedido de '.$record->supplier->name)
            ->modalDescription('Escribe lo que llegó. Si falta algo, el pedido queda abierto para recibir el resto después.')
            ->modalSubmitActionLabel('Registrar lo que llegó')
            ->fillForm(fn (PurchaseOrder $record) => ['items' => $record->items->mapWithKeys(fn (PurchaseOrderItem $item) => [$item->getKey() => ['received' => $item->pending()]])->all()])
            ->schema(fn (PurchaseOrder $record) => $record->items
                ->filter(fn (PurchaseOrderItem $item) => $item->pending() > 0)
                ->map(fn (PurchaseOrderItem $item) => Fieldset::make($item->supply->name.' · faltan '.$item->supply->quantity($item->pending()))
                    ->statePath('items.'.$item->getKey())
                    ->columns(['default' => 1, 'md' => 4])
                    ->schema([
                        $item->supply->tracks_serial
                            ? Textarea::make('serials')
                                ->label('Números de serie')
                                ->helperText('Uno por línea.')
                                ->rows(2)
                                ->columnSpan(['default' => 1, 'md' => 2])
                            : TextInput::make('received')
                                ->label('Llegó')
                                ->numeric()
                                ->minValue(0)
                                ->suffix($item->supply->unit),
                        TextInput::make('lot')
                            ->label('Lote')
                            ->maxLength(60),
                        DatePicker::make('expires_on')
                            ->label('Vence')
                            ->native(false)
                            ->displayFormat('d/m/Y')
                            ->closeOnDateSelection(),
                        TextInput::make('unit_cost')
                            ->label('Costo por unidad')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn () => auth()->user()->can('viewCosts', Supply::class)),
                    ]))
                ->values()
                ->all())
            ->action(function (array $data, PurchaseOrder $record, Component $livewire) {
                try {
                    app(InventoryService::class)->receiveOrder($record, $data['items'] ?? [], auth()->user());
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                $status = $record->fresh()->status;
                Notification::make()
                    ->success()
                    ->title(match ($status) {
                        PurchaseOrderStatus::Received => 'Pedido recibido. Ya está en el inventario.',
                        PurchaseOrderStatus::Partial => 'Se registró lo que llegó. El resto queda pendiente.',
                        default => 'No se registró nada: escribe lo que llegó.',
                    })
                    ->send();
            });
    }

    public static function getNavigationBadge(): ?string
    {
        $count = PurchaseOrder::query()->open()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Pedidos por llegar';
    }

    public static function getPages(): array
    {
        return [
            'index' => ManagePurchaseOrders::route('/'),
        ];
    }
}
