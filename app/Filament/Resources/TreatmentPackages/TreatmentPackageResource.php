<?php

namespace App\Filament\Resources\TreatmentPackages;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Forms\PaymentTermsFields;
use App\Filament\Resources\TreatmentPackages\Pages\ManageTreatmentPackages;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Paquetes que arma el doctor: qué incluye, a qué precio y cómo se puede pagar. */
class TreatmentPackageResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'paquetes';

    protected static ?string $model = TreatmentPackage::class;

    protected static ?string $modelLabel = 'paquete';

    protected static ?string $pluralModelLabel = 'paquetes';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    TextInput::make('name')
                        ->label('Nombre')
                        ->placeholder('Ortodoncia completa')
                        ->required()
                        ->maxLength(120)
                        ->columnSpan(['md' => 2]),
                    Toggle::make('is_active')
                        ->label('Se ofrece')
                        ->helperText('Desactívalo cuando ya no se ofrezca; los planes que lo usan no cambian.')
                        ->default(true),
                ]),
                Repeater::make('items')
                    ->label('Qué incluye')
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->table([
                        TableColumn::make('Tratamiento'),
                        TableColumn::make('Cantidad')->width('8rem'),
                        TableColumn::make('Sesiones')->width('8rem'),
                    ])
                    ->schema([
                        Select::make('treatment_id')
                            ->label('Tratamiento')
                            ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->get()
                                ->mapWithKeys(fn (Treatment $treatment) => [$treatment->getKey() => $treatment->name.((float) $treatment->price_from > 0 ? ' · '.Money::format($treatment->price_from) : '')]))
                            ->searchable()
                            ->required()
                            ->live(),
                        TextInput::make('quantity')
                            ->label('Cantidad')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true)
                            // Cada vez es una sesión: "Control × 18" son 18 sesiones.
                            ->afterStateUpdated(fn (?string $state, Set $set) => $set('sessions_planned', max(1, (int) $state))),
                        TextInput::make('sessions_planned')
                            ->label('Sesiones')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50)
                            ->default(1)
                            ->required(),
                    ])
                    ->addActionLabel('Agregar tratamiento')
                    ->minItems(1)
                    ->defaultItems(1)
                    ->live(),
                Grid::make(['default' => 1, 'md' => 3])->schema([
                    Text::make(fn (Get $get) => 'Precio normal: '.Money::format(self::normalPrice($get('items') ?? [])))
                        ->weight(FontWeight::Medium),
                    TextInput::make('price')
                        ->label('Precio del paquete')
                        ->prefix('S/')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->live(onBlur: true)
                        ->hint(function (Get $get): ?string {
                            $savings = round(self::normalPrice($get('items') ?? []) - (float) $get('price'), 2);

                            return $savings > 0 && filled($get('price')) ? 'Ahorra '.Money::format($savings) : null;
                        })
                        ->hintColor('success'),
                    Toggle::make('for_two')
                        ->label('2x1')
                        ->helperText('Con el mismo pago se atiende otra persona: un familiar o alguien que trae el paciente.')
                        ->default(false),
                ]),
                Section::make('Cómo se puede pagar')
                    ->compact()
                    ->schema([
                        Grid::make(['default' => 1, 'md' => 3])->schema([
                            Toggle::make('allows_upfront')
                                ->label('Pago único')
                                ->default(true)
                                ->live(),
                            TextInput::make('upfront_discount')
                                ->label('Descuento si paga todo junto')
                                ->prefix('S/')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->lte('price')
                                ->visible(fn (Get $get) => (bool) $get('allows_upfront'))
                                ->live(onBlur: true),
                        ]),
                        Toggle::make('allows_installments')
                            ->label('En cuotas')
                            ->live(),
                        Grid::make(['default' => 1, 'md' => 3])
                            ->visible(fn (Get $get) => (bool) $get('allows_installments'))
                            ->schema(PaymentTermsFields::make(fn (Get $get) => (float) $get('price'))),
                        Text::make(fn (Get $get) => implode(' ', TreatmentPlanService::packagePaymentOptions(self::draft($get))))
                            ->weight(FontWeight::Bold),
                    ]),
                Textarea::make('description')
                    ->label('Condiciones para el paciente')
                    ->placeholder('Ej.: incluye los controles de un año; los brackets estéticos se pagan aparte.')
                    ->rows(2),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('items.treatment'))
            ->columns([
                TextColumn::make('name')
                    ->label('Paquete')
                    ->searchable()
                    ->weight(FontWeight::Medium)
                    ->description(fn (TreatmentPackage $record) => $record->contents())
                    ->wrap(),
                TextColumn::make('normal_price')
                    ->label('Precio normal')
                    ->state(fn (TreatmentPackage $record) => Money::format($record->normalPrice())),
                TextColumn::make('price')
                    ->label('Precio del paquete')
                    ->formatStateUsing(fn (string $state) => Money::format($state))
                    ->weight(FontWeight::Bold)
                    ->description(fn (TreatmentPackage $record) => collect([
                        $record->savings() > 0 ? 'Ahorra '.Money::format($record->savings()) : null,
                        $record->for_two ? '2x1: atiende a 2 personas' : null,
                    ])->filter()->implode(' · ') ?: null),
                TextColumn::make('payment_options')
                    ->label('Cómo se paga')
                    ->state(fn (TreatmentPackage $record) => TreatmentPlanService::packagePaymentOptions($record))
                    ->listWithLineBreaks()
                    ->wrap(),
                ToggleColumn::make('is_active')
                    ->label('Se ofrece'),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Se ofrece')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Paquete')
                    ->modalWidth(Width::FourExtraLarge)
                    ->successNotificationTitle('Paquete guardado. Los planes que ya lo usan no cambian.'),
            ])
            ->emptyStateHeading('Todavía no hay paquetes')
            ->emptyStateDescription('Junta varios tratamientos, ponles un precio y elige cómo se puede pagar.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTreatmentPackages::route('/'),
        ];
    }

    /** @param  array<array-key, array<string, mixed>>  $items */
    private static function normalPrice(array $items): float
    {
        $prices = Treatment::query()->whereKey(collect($items)->pluck('treatment_id')->filter())->pluck('price_from', 'id');

        return round(collect($items)->sum(fn (array $item) => (int) ($item['quantity'] ?? 1) * (float) ($prices[$item['treatment_id'] ?? 0] ?? 0)), 2);
    }

    /** El paquete como está en el formulario, para mostrar cómo quedarían los pagos. */
    private static function draft(Get $get): TreatmentPackage
    {
        return new TreatmentPackage([
            'price' => (float) $get('price'),
            'allows_upfront' => (bool) $get('allows_upfront'),
            'upfront_discount' => (float) $get('upfront_discount'),
            'allows_installments' => (bool) $get('allows_installments'),
            'down_payment' => (float) $get('down_payment'),
            'installments' => filled($get('installments')) ? (int) $get('installments') : null,
            'monthly_amount' => filled($get('monthly_amount')) ? (float) $get('monthly_amount') : null,
        ]);
    }
}
