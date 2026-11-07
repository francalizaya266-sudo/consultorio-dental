<?php

namespace App\Filament\Resources\StockMovements;

use App\Enums\StockMovementType;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Patients\PatientResource;
use App\Filament\Resources\StockMovements\Pages\ListStockMovements;
use App\Filament\Resources\Supplies\SupplyResource;
use App\Models\StockMovement;
use App\Support\Quantity;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * La historia del inventario: qué entró, qué se usó en qué atención, qué se ajustó y por qué.
 * Buscando un lote o un número de serie aparecen los pacientes en los que se usó (por si el
 * fabricante lo retira).
 */
class StockMovementResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'movimientos';

    protected static ?string $model = StockMovement::class;

    protected static ?string $modelLabel = 'movimiento';

    protected static ?string $pluralModelLabel = 'movimientos';

    protected static ?string $navigationLabel = 'Movimientos';

    /** No va en el menú: cada material muestra los suyos, y para rastrear un lote o una serie se entra desde Inventario. */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationParentItem = 'Inventario';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsUpDown;

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supply', 'batch', 'patient', 'user']))
            // Lo último primero; lo registrado en el mismo momento, en el orden en que pasó.
            ->defaultSort(fn (Builder $query) => $query->orderByDesc('created_at')->orderByDesc('id'))
            ->columns([
                TextColumn::make('created_at')
                    ->label('Fecha')
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('supply.name')
                    ->label('Material')
                    ->url(fn (StockMovement $record) => SupplyResource::getUrl('view', ['record' => $record->supply_id]))
                    ->searchable()
                    ->weight('bold')
                    ->hidden(fn ($livewire) => $livewire instanceof RelationManager),
                TextColumn::make('type')
                    ->label('Qué fue')
                    ->badge()
                    ->description(fn (StockMovement $record) => $record->reverted_at ? 'Devuelto al reabrir' : null),
                TextColumn::make('quantity')
                    ->label('Cantidad')
                    ->formatStateUsing(fn (StockMovement $record) => ((float) $record->quantity > 0 ? '+' : '').Quantity::format($record->quantity, $record->supply->unit))
                    ->color(fn (StockMovement $record) => (float) $record->quantity > 0 ? 'success' : null),
                TextColumn::make('batch.lot')
                    ->label('Lote')
                    ->state(fn (StockMovement $record) => $record->batch
                        ? collect([$record->batch->lot, $record->batch->serial ? 'serie '.$record->batch->serial : null])->filter()->implode(' · ') ?: 'Sin lote'
                        : null)
                    ->description(fn (StockMovement $record) => $record->batch?->expires_on ? 'vence '.$record->batch->expires_on->format('d/m/Y') : null)
                    ->searchable(query: fn (Builder $query, string $search) => $query->whereHas('batch', fn (Builder $batch) => $batch->where('lot', 'like', "%{$search}%")->orWhere('serial', 'like', "%{$search}%")))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('patient')
                    ->label('Paciente')
                    ->state(fn (StockMovement $record) => $record->patient?->full_name)
                    ->url(fn (StockMovement $record) => $record->patient ? PatientResource::getUrl('view', ['record' => $record->patient]) : null)
                    ->placeholder('—')
                    // Qué se le puso a quién es parte de su historia clínica.
                    ->visible(fn () => auth()->user()->canSeeClinical()),
                TextColumn::make('who')
                    ->label('Quién')
                    ->state(fn (StockMovement $record) => $record->user?->name)
                    ->description(fn (StockMovement $record) => $record->reason)
                    ->placeholder('El sistema')
                    ->wrap(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label('Qué fue')
                    ->options(StockMovementType::class),
                SelectFilter::make('supply_id')
                    ->label('Material')
                    ->relationship('supply', 'name')
                    ->searchable()
                    ->preload()
                    ->hidden(fn ($livewire) => $livewire instanceof RelationManager),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from')->label('Desde')->native(false)->displayFormat('d/m/Y'),
                        DatePicker::make('until')->label('Hasta')->native(false)->displayFormat('d/m/Y'),
                    ])
                    ->query(fn (Builder $query, array $data) => $query
                        // El calendario del panel guarda la fecha con hora (00:00:00): se compara solo el día.
                        ->when($data['from'] ?? null, fn (Builder $q, $from) => $q->whereDate('created_at', '>=', CarbonImmutable::parse($from)->toDateString()))
                        ->when($data['until'] ?? null, fn (Builder $q, $until) => $q->whereDate('created_at', '<=', CarbonImmutable::parse($until)->toDateString()))),
            ])
            ->emptyStateHeading('Todavía no hay movimientos')
            ->emptyStateDescription('Aparecen solos con cada entrada, cada atención y cada conteo.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListStockMovements::route('/'),
        ];
    }
}
