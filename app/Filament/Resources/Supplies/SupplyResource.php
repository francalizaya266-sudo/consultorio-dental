<?php

namespace App\Filament\Resources\Supplies;

use App\Enums\SupplyCategory;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Suppliers\SupplierResource;
use App\Filament\Resources\Supplies\Pages\ManageSupplies;
use App\Filament\Resources\Supplies\Pages\ViewSupply;
use App\Filament\Resources\Supplies\RelationManagers\BatchesRelationManager;
use App\Filament\Resources\Supplies\RelationManagers\MovementsRelationManager;
use App\Filament\Resources\Supplies\Tables\SuppliesTable;
use App\Models\Supply;
use App\Models\SupplyBatch;
use App\Models\Treatment;
use App\Support\Money;
use App\Support\Phone;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Inventario: cada material con lo que hay, desde cuánto pedir y cuándo vence lo que queda.
 * Lo que hay no se escribe a mano: entra con las compras, sale con las atenciones y se corrige
 * con el conteo del mes.
 */
class SupplyResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'inventario';

    protected static ?string $model = Supply::class;

    protected static ?string $modelLabel = 'material';

    protected static ?string $pluralModelLabel = 'materiales';

    protected static ?string $navigationLabel = 'Inventario';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $recordTitleAttribute = 'name';

    /** En qué se suele contar un material. */
    public const UNITS = ['unidad', 'par', 'caja', 'carpule', 'jeringa', 'ml', 'g', 'sobre', 'rollo', 'frasco', 'kit', 'paquete'];

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->placeholder('Ej.: Lidocaína 2 % con epinefrina')
                    ->required()
                    ->maxLength(120)
                    ->columnSpanFull(),
                Select::make('category')
                    ->label('Categoría')
                    ->options(SupplyCategory::class)
                    ->required(),
                TextInput::make('unit')
                    ->label('Se cuenta en')
                    ->datalist(self::UNITS)
                    ->default('unidad')
                    ->required()
                    ->maxLength(30)
                    ->helperText('En singular: carpule, par, caja, ml…'),
                TextInput::make('min_stock')
                    ->label('Avisar cuando queden menos de')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->required()
                    ->helperText('Con 0 no avisa. Al bajar de aquí, se sugiere en el pedido.'),
                Select::make('supplier_id')
                    ->label('Proveedor')
                    ->relationship('supplier', 'name', modifyQueryUsing: fn (Builder $query) => $query->active())
                    ->searchable()
                    ->preload()
                    ->createOptionForm(SupplierResource::formComponents())
                    ->createOptionModalHeading('Nuevo proveedor'),
                TextInput::make('unit_cost')
                    ->label('Costo por unidad')
                    ->prefix('S/')
                    ->numeric()
                    ->minValue(0)
                    ->helperText('Se actualiza solo con el costo de cada compra.')
                    ->visible(fn () => auth()->user()->can('viewCosts', Supply::class)),
                Toggle::make('tracks_serial')
                    ->label('Cada unidad tiene número de serie (implantes)')
                    ->helperText('Al colocarlo, queda registrado en qué paciente quedó cada lote y serie.')
                    ->disabled(fn (?Supply $record) => $record?->movements()->exists() ?? false)
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label('Notas')
                    ->placeholder('Ej.: marca preferida, dónde se guarda')
                    ->rows(2)
                    ->columnSpanFull(),
                Toggle::make('is_active')
                    ->label('En uso')
                    ->helperText('Si ya no se compra, desactívalo: su historia se conserva.')
                    ->default(true)
                    ->hiddenOn('create'),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make()
                    ->columns(['default' => 2, 'md' => 4])
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('stock')
                            ->label('Hay')
                            ->formatStateUsing(fn (Supply $record) => $record->quantity($record->usableStock()))
                            ->belowContent(fn (Supply $record) => $record->expiredStock() > 0
                                ? 'Vencido aparte: '.$record->quantity($record->expiredStock()).'. Dalo de baja en Lotes.'
                                : null)
                            ->color(fn (Supply $record) => $record->isLow() ? 'danger' : null)
                            ->size(TextSize::Large)
                            ->weight(FontWeight::Bold),
                        TextEntry::make('min_stock')
                            ->label('Avisa con menos de')
                            ->formatStateUsing(fn (Supply $record) => (float) $record->min_stock > 0 ? $record->quantity($record->min_stock) : 'No avisa'),
                        TextEntry::make('next_expiry')
                            ->label('Vence primero')
                            ->state(fn (Supply $record) => $record->availableBatches()->whereNotNull('expires_on')->min('expires_on'))
                            ->date('d/m/Y')
                            ->color(fn (?string $state) => self::expiryColor($state))
                            ->placeholder('Sin vencimiento'),
                        TextEntry::make('unit_cost')
                            ->label('Costo por unidad')
                            ->formatStateUsing(fn ($state) => Money::format($state))
                            ->placeholder('Sin costo')
                            ->visible(fn () => auth()->user()->can('viewCosts', Supply::class)),
                        TextEntry::make('supplier.name')
                            ->label('Proveedor')
                            ->helperText(fn (Supply $record) => $record->supplier?->phone ? Phone::display($record->supplier->phone) : null)
                            ->placeholder('Sin proveedor'),
                        TextEntry::make('category')
                            ->label('Categoría'),
                        TextEntry::make('treatments')
                            ->label('Lo usan')
                            ->state(fn (Supply $record) => $record->treatments()->orderBy('name')->get()->map(fn (Treatment $treatment) => $treatment->name.' ('.$record->quantity($treatment->pivot->quantity).')')->all())
                            ->listWithLineBreaks()
                            ->placeholder('Ningún tratamiento todavía')
                            ->columnSpan(['default' => 2, 'md' => 2]),
                        TextEntry::make('notes')
                            ->label('Notas')
                            ->hidden(fn ($state) => blank($state))
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return SuppliesTable::configure($table);
    }

    /** Con lo que vence primero, lo vencido y lo que ya está pedido, para avisar sin una consulta por fila. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('supplier')
            ->addSelect([
                'next_expiry' => SupplyBatch::query()
                    ->selectRaw('min(expires_on)')
                    ->whereColumn('supply_id', 'supplies.id')
                    ->where('remaining', '>', 0),
                'expired_stock' => Supply::expiredStockQuery(),
                'on_order' => Supply::onOrderQuery(),
            ]);
    }

    /** Lo que hay que pedir: la misma cuenta que la pestaña "Por pedir" y que Hoy. */
    public static function getNavigationBadge(): ?string
    {
        $count = Supply::query()->toReorder()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Materiales por pedir';
    }

    public static function getRelations(): array
    {
        return [
            BatchesRelationManager::class,
            MovementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSupplies::route('/'),
            'view' => ViewSupply::route('/{record}'),
        ];
    }

    /**
     * Los materiales en uso, agrupados por categoría como en el estante (la unidad va al lado, en la cantidad).
     * Con $withStock, cada uno dice cuánto queda que se pueda usar: "Resina A2 · quedan 3 jeringas".
     *
     * @return array<string, array<int, string>>
     */
    public static function pickerOptions(bool $withStock = false): array
    {
        return Supply::query()->active()->orderBy('name')
            ->when($withStock, fn ($query) => $query->withSum(['batches as expired_stock' => fn ($batches) => $batches->expired()], 'remaining'))
            ->get()
            ->groupBy(fn (Supply $supply) => $supply->category->getLabel())
            ->sortKeys()
            ->map(fn ($supplies) => $supplies->mapWithKeys(fn (Supply $supply) => [$supply->getKey() => $supply->name
                .($withStock ? ' · quedan '.$supply->quantity(max(0, $supply->usableStock())) : '')])->all())
            ->all();
    }

    /** Rojo si ya venció, ámbar si vence en 30 días. */
    public static function expiryColor(mixed $date): ?string
    {
        if (blank($date)) {
            return null;
        }

        $date = Carbon::parse($date);

        return match (true) {
            $date->lt(today()) => 'danger',
            $date->lte(today()->addDays(Supply::EXPIRY_WARNING_DAYS)) => 'warning',
            default => null,
        };
    }
}
