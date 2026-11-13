<?php

namespace App\Filament\Resources\TreatmentPackages;

use App\Filament\Concerns\HasSettingsMenuGroup;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Forms\PaymentTermsFields;
use App\Filament\Resources\TreatmentPackages\Pages\ManageTreatmentPackages;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPackageItem;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
use Illuminate\Database\Eloquent\Model;

/** Paquetes que arma el doctor: qué incluye, a qué precio y cómo se puede pagar. */
class TreatmentPackageResource extends Resource
{
    use HasSettingsMenuGroup, HasSpanishLabels;

    protected static ?string $slug = 'paquetes';

    protected static ?string $model = TreatmentPackage::class;

    protected static ?string $modelLabel = 'paquete';

    protected static ?string $pluralModelLabel = 'paquetes';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    public static function settingsMenuGroup(): string
    {
        return 'Catálogo';
    }

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
                        ->helperText('Apágalo cuando ya no se ofrezca; los planes que lo usan no cambian.')
                        ->default(true)
                        ->inline(false),
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
                            // Los activos y, además, el que esta fila ya tenía guardado aunque se haya desactivado: si no,
                            // el paquete mostraba un número en vez del nombre y no dejaba guardar ni el precio.
                            ->options(fn (?Model $record) => Treatment::query()
                                ->where(fn (Builder $query) => $query->active()->when(
                                    $record instanceof TreatmentPackageItem,
                                    fn (Builder $query) => $query->orWhere('id', $record->treatment_id),
                                ))
                                ->orderBy('sort_order')
                                ->get()
                                ->mapWithKeys(fn (Treatment $treatment) => [$treatment->getKey() => $treatment->name
                                    .((float) $treatment->price_from > 0 ? ' · '.Money::format($treatment->price_from) : '')
                                    .($treatment->is_active ? '' : ' (inactivo)')]))
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
                        // Lo que ahorra el paciente; o el aviso de que así el paquete sale más caro que por separado.
                        ->hint(function (Get $get): ?string {
                            $savings = self::savings($get);

                            return match (true) {
                                $savings === null, $savings === 0.0 => null,
                                $savings > 0 => 'Ahorra '.Money::format($savings),
                                default => 'Cuesta '.Money::format(-$savings).' más que por separado',
                            };
                        })
                        ->hintColor(fn (Get $get) => (self::savings($get) ?? 0) < 0 ? 'warning' : 'success'),
                    // Con su nombre completo: en Tratamientos "duración" son los minutos de la cita; aquí, los meses que dura todo.
                    TextInput::make('duration_months')
                        ->label('Duración del tratamiento')
                        ->suffix('meses')
                        ->integer()
                        ->minValue(1)
                        ->maxValue(60)
                        ->helperText('Opcional (ortodoncia: 18). Sale en el presupuesto.'),
                ]),
                Toggle::make('for_two')
                    ->label('2x1')
                    ->helperText('Con el mismo pago se atiende otra persona: un familiar o alguien que trae el paciente.')
                    ->default(false),
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
                        Text::make(fn (Get $get) => self::paymentSummary($get))
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
            ->stackedOnMobile()
            ->modifyQueryUsing(fn (Builder $query) => $query->with('items.treatment'))
            ->columns([
                TextColumn::make('name')
                    ->label('Paquete')
                    ->searchable()
                    ->weight(FontWeight::Medium)
                    ->description(fn (TreatmentPackage $record) => $record->contents().(($duration = $record->durationText()) ? ' · dura '.$duration : ''))
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
                    ->label('Se ofrece')
                    ->afterStateUpdated(fn (TreatmentPackage $record, mixed $state) => Notification::make()
                        ->success()
                        ->title('"'.$record->name.'" '.($state ? 'se ofrece de nuevo' : 'ya no se ofrece'))
                        ->body($state ? null : 'Los planes que ya lo usan no cambian.')
                        ->send()),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('Se ofrece')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading('Editar paquete')
                    ->modalWidth(Width::FourExtraLarge)
                    ->successNotificationTitle('Paquete guardado. Los planes que ya lo usan no cambian.'),
                // La misma regla que en Tratamientos y Doctores: lo que nunca se usó se puede borrar (los de prueba);
                // lo que ya está en un plan solo se apaga con "Se ofrece".
                ActionGroup::make([
                    DeleteAction::make()
                        ->authorizationNotification()
                        ->modalDescription('Solo se borra un paquete que nunca se usó en un plan. Los demás se apagan con "Se ofrece".')
                        ->successNotificationTitle('Paquete borrado'),
                ]),
            ])
            ->defaultPaginationPageOption(25)
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

    /** Cuánto ahorra el paquete frente a pagar cada cosa por separado (negativo si sale más caro). Null si aún no hay con qué comparar. */
    private static function savings(Get $get): ?float
    {
        $normal = self::normalPrice($get('items') ?? []);

        return filled($get('price')) && $normal > 0 ? round($normal - (float) $get('price'), 2) : null;
    }

    /**
     * Cómo quedarían los pagos con lo que hay en el formulario. Recién encendido "En cuotas" todavía no hay nada
     * que revisar: se pide el número de cuotas en vez de mostrar un error.
     */
    private static function paymentSummary(Get $get): string
    {
        $draft = self::draft($get);
        $pendingInstallments = $draft->allows_installments && $draft->installments === null && $draft->monthly_amount === null;

        if (! $pendingInstallments) {
            return implode(' ', TreatmentPlanService::packagePaymentOptions($draft));
        }

        $draft->allows_installments = false;

        return implode(' ', [
            ...($draft->allows_upfront ? TreatmentPlanService::packagePaymentOptions($draft) : []),
            'En cuotas: pon el número de cuotas o la cuota mensual para ver cómo queda.',
        ]);
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
