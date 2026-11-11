<?php

namespace App\Filament\Resources\Suppliers;

use App\Enums\PurchaseOrderStatus;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Suppliers\Pages\ManageSuppliers;
use App\Models\Supplier;
use App\Support\Phone;
use BackedEnum;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** A quién se le compra. Con su WhatsApp, el pedido sale con un toque desde Compras. */
class SupplierResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'proveedores';

    protected static ?string $model = Supplier::class;

    protected static ?string $modelLabel = 'proveedor';

    protected static ?string $pluralModelLabel = 'proveedores';

    protected static ?string $navigationLabel = 'Proveedores';

    /** No va en el menú: son pocos y se entra desde Compras (también se crean al cargar un material). */
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $navigationParentItem = 'Inventario';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            ...self::formComponents(),
            Textarea::make('notes')
                ->label('Notas')
                ->placeholder('Ej.: entrega en 48 horas, pedido mínimo S/ 200')
                ->rows(2)
                ->columnSpanFull(),
            Toggle::make('is_active')
                ->label('Se le sigue comprando')
                ->default(true)
                ->hiddenOn('create'),
        ]);
    }

    /** @return list<TextInput> Lo básico, también para crearlo desde el material. */
    public static function formComponents(): array
    {
        return [
            TextInput::make('name')
                ->label('Empresa')
                ->required()
                ->maxLength(120),
            TextInput::make('contact_name')
                ->label('A quién se le escribe')
                ->maxLength(120),
            TextInput::make('phone')
                ->label('WhatsApp')
                ->tel()
                ->placeholder('987 654 321')
                ->maxLength(20)
                ->rule(fn () => function (string $attribute, mixed $value, Closure $fail) {
                    if (filled($value) && ($problem = Phone::problem((string) $value)) !== null) {
                        $fail($problem);
                    }
                })
                ->dehydrateStateUsing(fn (?string $state) => filled($state) && Phone::isValid($state) ? Phone::normalize($state) : $state),
            TextInput::make('email')
                ->label('Correo')
                ->email()
                ->maxLength(255),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->defaultSort('name')
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount(['supplies' => fn (Builder $supplies) => $supplies->active()])
                ->withCount(['purchaseOrders as open_orders_count' => fn (Builder $orders) => $orders->whereIn('status', [PurchaseOrderStatus::Sent, PurchaseOrderStatus::Partial])]))
            ->columns([
                TextColumn::make('name')
                    ->label('Proveedor')
                    ->description(fn (Supplier $record) => $record->contact_name)
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('phone')
                    ->label('WhatsApp')
                    ->formatStateUsing(fn (?string $state) => $state && Phone::isValid($state) ? Phone::display($state) : $state)
                    ->url(fn (Supplier $record) => $record->hasWhatsApp() ? 'https://wa.me/'.Phone::forWhatsApp($record->phone) : null, shouldOpenInNewTab: true)
                    ->color('success')
                    ->placeholder('Sin celular'),
                TextColumn::make('supplies_count')
                    ->label('Materiales'),
                TextColumn::make('open_orders_count')
                    ->label('Pedidos por llegar')
                    ->formatStateUsing(fn (int $state) => $state ?: '—')
                    ->color(fn (int $state) => $state > 0 ? 'warning' : null),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Se le sigue comprando')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->emptyStateHeading('Todavía no hay proveedores')
            ->emptyStateDescription('Agrega a quién le compras, con su WhatsApp, para pedirle lo que falta con un toque.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageSuppliers::route('/'),
        ];
    }
}
