<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\PlanItemStatus;
use App\Enums\TreatmentKind;
use App\Models\Patient;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
use App\Models\TreatmentPlanItem;
use App\Services\Clinical\TreatmentPlanService;
use App\Support\Money;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;
use Illuminate\Support\Str;

/**
 * Plan de tratamiento: procedimientos con pieza, precio y sesiones, y el total al momento.
 * Lo que el paciente ya firmó no cambia de precio ni se quita: se cancela y se agrega el correcto.
 */
class TreatmentPlanForm
{
    /**
     * @param  Closure(): array<array-key, array<string, mixed>>|null  $suggestedItems  Renglones con que empieza un plan nuevo.
     * @param  Patient|null  $patient  El paciente del plan: en un 2x1 se ofrece primero su familia.
     */
    public static function configure(Schema $schema, ?Closure $suggestedItems = null, ?Patient $patient = null): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('title')
                    ->label('Nombre del plan')
                    ->default('Plan de tratamiento')
                    ->maxLength(255),

                Text::make(fn (?TreatmentPlan $record) => 'Va incluido en el 2x1 de '.$record?->includedIn?->patient?->full_name.': lo que viene del paquete no tiene costo.')
                    ->color('success')
                    ->visible(fn (?TreatmentPlan $record) => $record?->included_in_plan_id !== null),

                Select::make('add_package')
                    ->label('Agregar un paquete')
                    ->placeholder('Suma los tratamientos de un paquete, con su precio')
                    ->options(fn () => TreatmentPackage::query()->active()->orderBy('name')->get()
                        ->mapWithKeys(fn (TreatmentPackage $package) => [$package->getKey() => $package->name.' · '.Money::format($package->price)]))
                    ->visible(fn () => TreatmentPackage::query()->active()->exists())
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(fn (?string $state, Get $get, Set $set) => self::addPackage($state, $get, $set)),

                Repeater::make('items')
                    ->label('Procedimientos')
                    ->relationship()
                    ->orderColumn('sort_order')
                    ->default($suggestedItems ?? [])
                    ->addActionLabel('Agregar procedimiento')
                    ->minItems(1)
                    ->columns(12)
                    ->live()
                    // Si se quita o se cancela algo del paquete, su precio se pone al día.
                    ->afterStateUpdated(fn (Get $get, Set $set) => $set('package_price', self::moneyText(self::packageTotal($get('items') ?? []))))
                    ->itemLabel(fn (array $state) => self::itemLabel($state))
                    ->belowLabel(fn (?TreatmentPlan $record) => $record?->accepted_at
                        ? 'Lo que el paciente ya firmó queda con su precio. Si hay que corregirlo, márcalo como cancelado y agrega el correcto: lo que agregues se firma aparte.'
                        : null)
                    ->deleteAction(fn (Action $action) => $action->visible(fn (array $arguments, Repeater $component) => $component->isDeletable()
                        && blank($component->getRawState()[$arguments['item']]['accepted_at'] ?? null)))
                    ->schema([
                        Hidden::make('accepted_at')
                            ->dehydrated(false),
                        Hidden::make('treatment_package_id'),
                        Select::make('treatment_id')
                            ->disabled(fn (Get $get) => self::isAgreed($get))
                            ->label('Tratamiento')
                            ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                            ->searchable()
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get) {
                                if ($treatment = Treatment::find($state)) {
                                    $item = app(TreatmentPlanService::class)->itemFor($treatment);
                                    $set('description', $item['description']);
                                    $set('unit_price', $item['unit_price']);
                                }
                            })
                            ->columnSpan(['default' => 12, 'lg' => 4]),
                        TextInput::make('description')
                            ->disabled(fn (Get $get) => self::isAgreed($get))
                            ->label('Detalle')
                            ->helperText(fn (Get $get) => filled($get('treatment_package_id')) ? 'Del paquete '.TreatmentPackage::withTrashed()->find($get('treatment_package_id'))?->name.': va en su precio.' : null)
                            ->required()
                            ->maxLength(255)
                            ->columnSpan(['default' => 12, 'lg' => 4]),
                        TextInput::make('tooth')
                            ->label('Pieza')
                            ->placeholder('36')
                            ->maxLength(10)
                            ->columnSpan(['default' => 6, 'lg' => 2]),
                        TextInput::make('surfaces')
                            ->label('Superficies')
                            ->placeholder('MO')
                            ->maxLength(10)
                            ->columnSpan(['default' => 6, 'lg' => 2]),
                        TextInput::make('quantity')
                            ->disabled(fn (Get $get) => self::isAgreed($get))
                            ->label('Cantidad')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99)
                            ->default(1)
                            ->required()
                            ->live(onBlur: true)
                            // En un paquete, más controles no suben el precio: se reparte el mismo.
                            ->afterStateUpdated(function (Get $get, Set $set) {
                                if (filled($get('treatment_package_id')) && ! self::packageAgreed($get('../../items') ?? []) && is_numeric($get('../../package_price'))) {
                                    $set('../../items', self::spreadPackage($get('../../items') ?? [], (float) $get('../../package_price')));
                                }
                            })
                            ->columnSpan(['default' => 4, 'lg' => 2]),
                        // Lo del paquete no lleva precio a la vista: va en el precio del paquete, debajo.
                        TextInput::make('unit_price')
                            ->disabled(fn (Get $get) => self::isAgreed($get))
                            ->label('Precio')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->live(onBlur: true)
                            ->hidden(fn (Get $get) => filled($get('treatment_package_id')))
                            ->dehydratedWhenHidden()
                            ->columnSpan(['default' => 8, 'lg' => 3]),
                        TextInput::make('discount')
                            ->disabled(fn (Get $get) => self::isAgreed($get))
                            ->label('Descuento')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->live(onBlur: true)
                            ->hidden(fn (Get $get) => filled($get('treatment_package_id')))
                            ->dehydratedWhenHidden()
                            ->columnSpan(['default' => 6, 'lg' => 3]),
                        TextInput::make('sessions_planned')
                            ->label('Sesiones')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50)
                            ->default(1)
                            ->required()
                            ->columnSpan(['default' => 6, 'lg' => 2]),
                        // Los controles van a la frecuencia de sus brackets: de ella sale la fecha que se le sugiere.
                        Select::make('session_interval_days')
                            ->label('Control cada')
                            ->options(fn (Get $get) => TreatmentPlanItem::frequencyOptions(Treatment::find($get('treatment_id'))?->nextSessionDays()))
                            ->placeholder(fn (Get $get) => ($days = Treatment::find($get('treatment_id'))?->nextSessionDays()) ? ucfirst(TreatmentPlanItem::everyText($days)) : null)
                            ->visible(fn (Get $get) => TreatmentKind::isControl(Treatment::find($get('treatment_id'))))
                            ->columnSpan(['default' => 6, 'lg' => 2]),
                        Select::make('status')
                            ->label('Estado')
                            ->options(PlanItemStatus::class)
                            ->default(PlanItemStatus::Pending)
                            ->selectablePlaceholder(false)
                            ->visibleOn('edit')
                            ->columnSpan(['default' => 12, 'lg' => 2]),
                    ]),

                // El paquete con un solo precio: si se cambia, se reparte solo entre lo que incluye.
                TextInput::make('package_price')
                    ->label(fn (Get $get) => collect(self::packageRows($get('items') ?? []))->pluck('treatment_package_id')->unique()->count() > 1 ? 'Precio de los paquetes' : 'Precio del paquete')
                    ->helperText('Lo que paga por todo el paquete. Lo que no es del paquete va aparte, con su precio.')
                    ->prefix('S/')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(999999)
                    ->dehydrated(false)
                    ->live(onBlur: true)
                    ->afterStateHydrated(fn (TextInput $component, Get $get) => $component->state(self::moneyText(self::packageTotal($get('items') ?? []))))
                    ->afterStateUpdated(fn (mixed $state, Get $get, Set $set) => is_numeric($state)
                        ? $set('items', self::spreadPackage($get('items') ?? [], (float) $state))
                        : $set('package_price', self::moneyText(self::packageTotal($get('items') ?? []))))
                    ->disabled(fn (Get $get) => self::packageAgreed($get('items') ?? []))
                    ->visible(fn (Get $get) => self::packageRows($get('items') ?? []) !== []),

                // Se lee al guardar (ver el gestor de planes): con ella se arma el plan sin costo de la otra persona.
                self::companionSelect($patient)
                    ->dehydrated(false)
                    ->visible(fn (Get $get, ?TreatmentPlan $record) => $patient !== null
                        && self::twoForOnePackage($get('items') ?? []) !== null
                        && $record?->included_in_plan_id === null
                        && ! $record?->companionPlans()->exists()),

                Text::make(fn (?TreatmentPlan $record) => '2x1: con este plan se atiende también a '.$record?->companionNames().', sin costo.')
                    ->color('success')
                    ->visible(fn (?TreatmentPlan $record) => $record?->companionNames() !== null),

                Text::make(fn (Get $get) => 'Total: '.Money::format(self::total($get('items') ?? [])))
                    ->size(TextSize::Large)
                    ->weight(FontWeight::Bold),

                Textarea::make('notes')
                    ->label('Notas para el paciente')
                    ->placeholder('Ej.: se puede pagar por sesión.')
                    ->rows(2),
            ]);
    }

    /** @param  array<array-key, array<string, mixed>>  $items */
    public static function total(array $items): float
    {
        return round(collect($items)
            ->reject(fn (array $item) => in_array($item['status'] ?? null, [PlanItemStatus::Cancelled, PlanItemStatus::Cancelled->value], true))
            ->sum(fn (array $item) => (int) ($item['quantity'] ?? 1) * (float) ($item['unit_price'] ?? 0) - (float) ($item['discount'] ?? 0)), 2);
    }

    /** 2x1: a quién más se atiende con el paquete. Primero la familia del paciente; se puede buscar a cualquiera. */
    public static function companionSelect(?Patient $patient): Select
    {
        return Select::make('companion_patient_id')
            ->label('2x1: ¿a quién más se atiende?')
            ->placeholder('Un familiar o alguien que trae el paciente')
            ->helperText('Se le arma su plan con lo mismo del paquete, sin costo. Queda aceptado cuando este paciente firme el suyo.')
            ->options(fn () => $patient?->account?->patients()->whereKeyNot($patient->getKey())->orderBy('first_name')->with('account')->get()
                ->mapWithKeys(fn (Patient $relative) => [$relative->getKey() => $relative->selectLabel()]) ?? [])
            ->searchable()
            ->getSearchResultsUsing(fn (string $search) => Patient::query()
                ->search($search)
                ->whereKeyNot($patient?->getKey())
                ->with('account')
                ->limit(20)
                ->get()
                ->mapWithKeys(fn (Patient $other) => [$other->getKey() => $other->selectLabel()]))
            ->getOptionLabelUsing(fn ($value) => Patient::with('account')->find($value)?->selectLabel());
    }

    /**
     * El paquete 2x1 entre los procedimientos del formulario, si hay uno.
     *
     * @param  array<array-key, array<string, mixed>>  $items
     */
    public static function twoForOnePackage(array $items): ?TreatmentPackage
    {
        $packageIds = collect($items)
            ->reject(fn (array $item) => in_array($item['status'] ?? null, [PlanItemStatus::Cancelled, PlanItemStatus::Cancelled->value], true))
            ->pluck('treatment_package_id')
            ->filter()
            ->unique();

        return $packageIds->isEmpty() ? null : TreatmentPackage::withTrashed()->whereKey($packageIds->all())->where('for_two', true)->first();
    }

    /** Suma al plan los tratamientos del paquete, con el precio del paquete repartido entre ellos. */
    private static function addPackage(?string $packageId, Get $get, Set $set): void
    {
        $package = TreatmentPackage::query()->active()->find($packageId);

        if (! $package) {
            return;
        }

        $items = $get('items') ?? [];

        foreach (app(TreatmentPlanService::class)->itemsFromPackage($package) as $item) {
            $items[(string) Str::uuid()] = [...$item, 'accepted_at' => null, 'status' => PlanItemStatus::Pending->value];
        }

        $set('items', $items);
        $set('package_price', self::moneyText(self::packageTotal($items)));
        $set('add_package', null);

        if (blank($get('title')) || $get('title') === 'Plan de tratamiento') {
            $set('title', $package->name);
        }
    }

    /**
     * Los renglones de paquete que siguen en pie.
     *
     * @param  array<array-key, array<string, mixed>>  $items
     * @return array<array-key, array<string, mixed>>
     */
    private static function packageRows(array $items): array
    {
        return array_filter($items, fn (array $item) => filled($item['treatment_package_id'] ?? null)
            && ! in_array($item['status'] ?? null, [PlanItemStatus::Cancelled, PlanItemStatus::Cancelled->value], true));
    }

    /** @param  array<array-key, array<string, mixed>>  $items */
    private static function packageTotal(array $items): float
    {
        return self::total(self::packageRows($items));
    }

    /**
     * Ya firmó algo del paquete: su precio quedó.
     *
     * @param  array<array-key, array<string, mixed>>  $items
     */
    private static function packageAgreed(array $items): bool
    {
        return collect(self::packageRows($items))->contains(fn (array $item) => filled($item['accepted_at'] ?? null));
    }

    /**
     * Reparte $total entre lo del paquete según el precio normal de cada cosa; lo demás queda como está.
     *
     * @param  array<array-key, array<string, mixed>>  $items
     * @return array<array-key, array<string, mixed>>
     */
    private static function spreadPackage(array $items, float $total): array
    {
        $rows = self::packageRows($items);
        $catalog = Treatment::query()->whereKey(array_filter(array_column($rows, 'treatment_id')))->pluck('price_from', 'id');

        $prices = TreatmentPlanService::spreadPrice($total, array_map(fn (array $row) => [
            'quantity' => max(1, (int) ($row['quantity'] ?? 1)),
            'price' => (float) ($catalog[$row['treatment_id'] ?? null] ?? 0) > 0 ? (float) $catalog[$row['treatment_id']] : (float) ($row['unit_price'] ?? 0),
        ], $rows));

        foreach ($prices as $key => $price) {
            $items[$key]['unit_price'] = self::moneyText($price['unit_price']);
            $items[$key]['discount'] = self::moneyText($price['discount']);
        }

        return $items;
    }

    private static function moneyText(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /** El paciente ya firmó este procedimiento: su precio queda como se acordó. */
    private static function isAgreed(Get $get): bool
    {
        return filled($get('accepted_at'));
    }

    /** @param  array<string, mixed>  $state */
    private static function itemLabel(array $state): ?string
    {
        if (blank($state['description'] ?? null)) {
            return null;
        }

        return collect([
            $state['description'],
            filled($state['tooth'] ?? null) ? 'pieza '.$state['tooth'].(filled($state['surfaces'] ?? null) ? " ({$state['surfaces']})" : '') : null,
            // Lo del paquete no muestra su parte del precio: va en el del paquete.
            filled($state['treatment_package_id'] ?? null)
                ? 'en el paquete'
                : Money::format((int) ($state['quantity'] ?? 1) * (float) ($state['unit_price'] ?? 0) - (float) ($state['discount'] ?? 0)),
            filled($state['accepted_at'] ?? null) ? 'firmado' : null,
        ])->filter()->implode(' · ');
    }
}
