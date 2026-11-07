<?php

namespace App\Filament\Resources\Treatments;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Forms\WebPhotoUpload;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Doctor;
use App\Models\Setting;
use App\Models\Supply;
use App\Models\Treatment;
use App\Services\Inventory\InventoryService;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class TreatmentResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'tratamientos';

    protected static ?string $model = Treatment::class;

    protected static ?string $modelLabel = 'tratamiento';

    protected static ?string $pluralModelLabel = 'tratamientos';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(100)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Set $set, ?string $state, string $operation) => $operation === 'create' ? $set('slug', Str::slug($state)) : null),
                TextInput::make('slug')
                    ->label('Dirección en la web')
                    ->prefix('/tratamientos/')
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->alphaDash(),
                TextInput::make('duration_minutes')
                    ->label('Duración')
                    ->suffix('min')
                    ->numeric()
                    ->minValue(5)
                    ->maxValue(480)
                    ->step(5)
                    ->default(30)
                    ->required()
                    ->helperText('Cada doctor puede tener su propia duración.'),
                TextInput::make('buffer_minutes')
                    ->label('Limpieza y esterilización')
                    ->suffix('min')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(60)
                    ->placeholder(fn () => (string) Setting::get('agenda.buffer_minutes', 10))
                    ->helperText('Vacío = el valor general del consultorio.'),
                TextInput::make('price_from')
                    ->label('Precio desde')
                    ->prefix('S/')
                    ->numeric()
                    ->minValue(0),
                TextInput::make('sessions_label')
                    ->label('Sesiones')
                    ->placeholder('Por ejemplo: 2 a 3 sesiones')
                    ->maxLength(50),
                Textarea::make('summary')
                    ->label('Resumen para la web')
                    ->rows(2)
                    ->maxLength(500)
                    ->helperText('Una o dos frases: sale en la lista de tratamientos.')
                    ->columnSpanFull(),
                Section::make('Página del tratamiento')
                    ->columnSpanFull()
                    ->collapsed()
                    ->schema([
                        MarkdownEditor::make('description')
                            ->label('Descripción')
                            ->toolbarButtons([['bold', 'italic', 'link'], ['heading', 'bulletList', 'orderedList'], ['undo', 'redo']])
                            ->helperText('En qué consiste, para quién es y cómo es cada sesión.'),
                        WebPhotoUpload::make('image_path', 'tratamientos')
                            ->label('Foto'),
                        TextInput::make('seo_title')
                            ->label('Título en Google')
                            ->maxLength(70)
                            ->placeholder(fn (Get $get) => $get('name').' en '.(Setting::get('clinic.district') ?: 'Lima'))
                            ->helperText('Vacío = se arma solo con el nombre y el distrito.'),
                        Textarea::make('seo_description')
                            ->label('Descripción en Google')
                            ->rows(2)
                            ->maxLength(160)
                            ->helperText('Vacío = se usa el resumen.'),
                    ]),
                Fieldset::make('Opciones')
                    ->columns(2)
                    ->schema([
                        Toggle::make('online_bookable')
                            ->label('Se reserva en la web como primera cita')
                            ->helperText('Solo evaluaciones o tratamientos simples.')
                            ->live(),
                        // Sin un doctor que lo atienda y reserve por la web, no aparece en la reserva: se pregunta aquí mismo.
                        CheckboxList::make('doctors')
                            ->label('¿Quién lo atiende?')
                            ->relationship('doctors', 'name', modifyQueryUsing: fn (Builder $query) => $query->active()->orderBy('sort_order'))
                            ->getOptionLabelFromRecordUsing(fn (Doctor $doctor) => $doctor->name.($doctor->accepts_online_booking ? '' : ' (no recibe reservas por la web)'))
                            ->helperText(fn (Get $get) => Doctor::query()->active()->where('accepts_online_booking', true)->whereKey($get('doctors') ?? [])->exists()
                                ? 'Aparece en la reserva de la web con los doctores marcados que reciben reservas por la web.'
                                : new HtmlString('<span class="help-warning">Así no aparece en la reserva: marca al menos un doctor que reciba reservas por la web.</span>'))
                            ->live()
                            ->visible(fn (Get $get) => (bool) $get('online_bookable'))
                            ->columnSpanFull(),
                        Toggle::make('is_evaluation')
                            ->label('Es una evaluación'),
                        Toggle::make('show_price')
                            ->label('Mostrar precio en la web')
                            ->default(true),
                        Toggle::make('is_public')
                            ->label('Mostrar en la web')
                            ->default(true),
                        Toggle::make('is_active')
                            ->label('Activo')
                            ->default(true),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')
                    ->label('Tratamiento')
                    ->searchable()
                    ->description(fn (Treatment $record) => $record->sessions_label),
                TextColumn::make('duration_minutes')
                    ->label('Duración')
                    ->suffix(' min')
                    ->description(fn (Treatment $record) => '+'.$record->bufferMinutes().' de esterilización'),
                TextColumn::make('price_from')
                    ->label('Desde')
                    ->money('PEN', locale: 'es_PE')
                    ->placeholder('—'),
                // Marcado pero sin un doctor que lo atienda por la web: no aparece en la reserva, y se avisa.
                IconColumn::make('online_bookable')
                    ->label('Reserva web')
                    ->icon(fn (Treatment $record) => match (true) {
                        ! $record->online_bookable => Heroicon::OutlinedXCircle,
                        ! $record->has_web_doctor => Heroicon::OutlinedExclamationTriangle,
                        default => Heroicon::OutlinedCheckCircle,
                    })
                    ->color(fn (Treatment $record) => match (true) {
                        ! $record->online_bookable => 'danger',
                        ! $record->has_web_doctor => 'warning',
                        default => 'success',
                    })
                    ->tooltip(fn (Treatment $record) => $record->online_bookable && ! $record->has_web_doctor
                        ? 'No aparece en la reserva: ningún doctor que reciba reservas por la web lo atiende.'
                        : null),
                // Lo que usa cada atención: lo descuenta del inventario al terminarla.
                TextColumn::make('supplies_count')
                    ->label('Materiales')
                    ->state(fn (Treatment $record) => $record->supplies->count() ?: null)
                    ->description(fn (Treatment $record) => $record->supplies->isNotEmpty() && auth()->user()->can('viewCosts', Supply::class) ? Money::format(app(InventoryService::class)->recipeCost($record)).' por atención' : null)
                    ->placeholder('Sin receta')
                    ->visible(fn () => Supply::query()->exists()),
                ToggleColumn::make('is_active')
                    ->label('Activo'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supplies')
                ->withExists(['doctors as has_web_doctor' => fn (Builder $doctors) => $doctors->active()->where('accepts_online_booking', true)]))
            ->recordActions([
                TreatmentRecipeAction::make(),
                EditAction::make(),
                // Borrar va en el menú para que la fila entre entera. Con citas o cobros no se borra: avisa que se apaga "Activo".
                ActionGroup::make([
                    DeleteAction::make()
                        ->authorizationNotification(),
                    RestoreAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTreatments::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
