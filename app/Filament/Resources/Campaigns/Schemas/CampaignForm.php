<?php

namespace App\Filament\Resources\Campaigns\Schemas;

use App\Enums\DiscountType;
use App\Filament\Forms\WebPhotoUpload;
use App\Models\Treatment;
use App\Models\TreatmentPackage;
use App\Support\Money;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class CampaignForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make('Campaña')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')
                            ->label('Nombre')
                            ->placeholder('Blanqueamiento de verano')
                            ->required()
                            ->maxLength(120)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                        TextInput::make('slug')
                            ->label('Dirección en la web')
                            ->prefix('/promociones/')
                            ->required()
                            ->alphaDash()
                            ->unique(ignoreRecord: true),
                        Select::make('treatment_id')
                            ->label('Tratamiento')
                            ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                            ->searchable(),
                        Select::make('treatment_package_id')
                            ->label('Paquete que ofrece')
                            ->helperText('En la web se ve qué incluye, el precio y cómo se paga. En la consulta ya viene elegido.')
                            ->options(fn () => TreatmentPackage::query()->active()->orderBy('name')->get()
                                ->mapWithKeys(fn (TreatmentPackage $package) => [$package->getKey() => $package->name.' · '.Money::format($package->price)]))
                            ->getOptionLabelUsing(fn ($value) => TreatmentPackage::withTrashed()->find($value)?->name)
                            ->searchable(),
                        TextInput::make('promo_code')
                            ->label('Código')
                            ->placeholder('BLANCO20')
                            ->maxLength(30)
                            ->unique(ignoreRecord: true)
                            ->dehydrateStateUsing(fn (?string $state) => $state ? Str::upper($state) : null),
                        DateTimePicker::make('starts_at')
                            ->label('Empieza')
                            ->seconds(false)
                            ->default(today())
                            ->required(),
                        DateTimePicker::make('ends_at')
                            ->label('Termina')
                            ->seconds(false)
                            ->after('starts_at')
                            ->required(),
                        Select::make('discount_type')
                            ->label('Descuento')
                            ->options(DiscountType::class)
                            ->default(DiscountType::None->value)
                            ->required()
                            ->live(),
                        TextInput::make('discount_value')
                            ->label('Valor')
                            ->numeric()
                            ->minValue(0)
                            ->prefix(fn (Get $get) => in_array($get('discount_type'), [DiscountType::Amount, DiscountType::Amount->value], true) ? 'S/' : null)
                            ->suffix(fn (Get $get) => in_array($get('discount_type'), [DiscountType::Percent, DiscountType::Percent->value], true) ? '%' : null)
                            ->hidden(fn (Get $get) => in_array($get('discount_type'), [null, DiscountType::None, DiscountType::None->value], true))
                            ->required(fn (Get $get) => ! in_array($get('discount_type'), [null, DiscountType::None, DiscountType::None->value], true)),
                        TextInput::make('max_redemptions')
                            ->label('Cupos')
                            ->numeric()
                            ->minValue(1)
                            ->placeholder('Sin límite'),
                    ]),
                Section::make('En la web')
                    ->columns(2)
                    ->schema([
                        WebPhotoUpload::make('image_path', 'promociones')
                            ->label('Imagen')
                            ->helperText('Horizontal. Sale en el inicio y en la página de la promoción.')
                            ->columnSpanFull(),
                        Textarea::make('summary')
                            ->label('Resumen')
                            ->rows(2)
                            ->maxLength(500)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label('Detalle y condiciones')
                            ->rows(4)
                            ->columnSpanFull(),
                        Textarea::make('whatsapp_text')
                            ->label('Mensaje para difundir por WhatsApp')
                            ->rows(3)
                            ->columnSpanFull(),
                        Toggle::make('show_on_home')
                            ->label('Mostrar en la página de inicio')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Activa')
                            ->default(true),
                    ]),
            ]);
    }
}
