<?php

namespace App\Filament\Resources\Supplies;

use App\Exceptions\InventoryException;
use App\Models\Supply;
use App\Models\SupplyBatch;
use App\Services\Inventory\InventoryService;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Support\Icons\Heroicon;

/** Lo que mueve el inventario a mano: una entrada (lo que llegó o lo que ya había) y una baja. */
final class SupplyActions
{
    /** Llegó material sin pedido de por medio, o se carga lo que ya había al empezar. */
    public static function receive(?Closure $supply = null): Action
    {
        $supply ??= fn (Supply $record) => $record;

        return Action::make('receive')
            ->label('Entrada')
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->visible(fn () => auth()->user()->can('moveStock', Supply::class))
            ->modalHeading(fn ($record) => 'Entrada de '.$supply($record)->name)
            ->modalDescription('Lo que llegó suma a lo que hay. El lote y el vencimiento sirven para usar primero lo que vence antes.')
            ->modalSubmitActionLabel('Registrar entrada')
            ->schema(fn ($record) => [
                Grid::make(2)->schema([
                    ...self::quantityFields($supply($record)),
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
                ]),
            ])
            ->action(function (array $data, $record) use ($supply) {
                $item = $supply($record);
                $batch = ['lot' => $data['lot'] ?? null, 'expires_on' => $data['expires_on'] ?? null, 'unit_cost' => $data['unit_cost'] ?? null];

                try {
                    $item->tracks_serial
                        ? app(InventoryService::class)->receiveSerials($item, InventoryService::serials($data['serials'] ?? ''), auth()->user(), $batch)
                        : app(InventoryService::class)->receive($item, (float) $data['quantity'], auth()->user(), $batch);
                } catch (InventoryException $e) {
                    Notification::make()->danger()->title($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Entrada registrada. Ahora hay '.$item->quantity($item->fresh()->stock).'.')->send();
            });
    }

    /** Un lote que venció, se dañó o se perdió. */
    public static function writeOff(): Action
    {
        return Action::make('writeOff')
            ->label('Dar de baja')
            ->icon(Heroicon::OutlinedTrash)
            ->color('danger')
            ->visible(fn (SupplyBatch $record) => (float) $record->remaining > 0 && auth()->user()->can('moveStock', Supply::class))
            ->modalHeading(fn (SupplyBatch $record) => 'Dar de baja: '.$record->label())
            ->modalDescription('Sale del stock y queda registrado con el motivo.')
            ->modalSubmitActionLabel('Dar de baja')
            ->fillForm(fn (SupplyBatch $record) => ['quantity' => (float) $record->remaining, 'reason' => $record->isExpired() ? 'Venció' : null])
            ->schema(fn (SupplyBatch $record) => [
                TextInput::make('quantity')
                    ->label('Cantidad')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue((float) $record->remaining)
                    ->suffix($record->loadMissing('supply')->supply->unit)
                    ->required()
                    ->hidden($record->supply->tracks_serial),
                Select::make('reason')
                    ->label('Motivo')
                    ->options(['Venció' => 'Venció', 'Se dañó' => 'Se dañó', 'Se perdió' => 'Se perdió', 'Se contaminó' => 'Se contaminó'])
                    ->required(),
            ])
            ->action(function (array $data, SupplyBatch $record) {
                app(InventoryService::class)->writeOff($record, (float) ($data['quantity'] ?? $record->remaining), $data['reason'], auth()->user());
                Notification::make()->success()->title('Dado de baja.')->send();
            });
    }

    /** @return list<TextInput|Textarea> Cuánto llegó; en los implantes, los números de serie. */
    private static function quantityFields(Supply $supply): array
    {
        if ($supply->tracks_serial) {
            return [
                Textarea::make('serials')
                    ->label('Números de serie')
                    ->helperText('Uno por línea: cada uno es una unidad.')
                    ->rows(3)
                    ->required()
                    ->columnSpanFull(),
            ];
        }

        return [
            TextInput::make('quantity')
                ->label('Cantidad')
                ->numeric()
                ->minValue(0.01)
                ->suffix($supply->unit)
                ->required()
                ->autofocus()
                ->columnSpanFull(),
        ];
    }
}
