<?php

namespace App\Filament\Resources\Patients\Schemas;

use App\Enums\PlanItemStatus;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Models\TreatmentPlan;
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
     */
    public static function configure(Schema $schema, ?Closure $suggestedItems = null): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('title')
                    ->label('Nombre del plan')
                    ->default('Plan de tratamiento')
                    ->maxLength(255),

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
                            ->helperText(fn (Get $get) => filled($get('treatment_package_id')) ? 'Del paquete '.TreatmentPackage::withTrashed()->find($get('treatment_package_id'))?->name : null)
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
                            ->columnSpan(['default' => 4, 'lg' => 2]),
                        TextInput::make('unit_price')
                            ->disabled(fn (Get $get) => self::isAgreed($get))
                            ->label('Precio')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->required()
                            ->live(onBlur: true)
                            ->columnSpan(['default' => 8, 'lg' => 3]),
                        TextInput::make('discount')
                            ->disabled(fn (Get $get) => self::isAgreed($get))
                            ->label('Descuento')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->live(onBlur: true)
                            ->columnSpan(['default' => 6, 'lg' => 3]),
                        TextInput::make('sessions_planned')
                            ->label('Sesiones')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(50)
                            ->default(1)
                            ->required()
                            ->columnSpan(['default' => 6, 'lg' => 2]),
                        Select::make('status')
                            ->label('Estado')
                            ->options(PlanItemStatus::class)
                            ->default(PlanItemStatus::Pending)
                            ->selectablePlaceholder(false)
                            ->visibleOn('edit')
                            ->columnSpan(['default' => 12, 'lg' => 2]),
                    ]),

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
        $set('add_package', null);

        if (blank($get('title')) || $get('title') === 'Plan de tratamiento') {
            $set('title', $package->name);
        }
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
            Money::format((int) ($state['quantity'] ?? 1) * (float) ($state['unit_price'] ?? 0) - (float) ($state['discount'] ?? 0)),
            filled($state['accepted_at'] ?? null) ? 'firmado' : null,
        ])->filter()->implode(' · ');
    }
}
