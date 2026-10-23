<?php

namespace App\Filament\Resources\Treatments;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Forms\WebPhotoUpload;
use App\Filament\Resources\Treatments\Pages\ManageTreatments;
use App\Models\Setting;
use App\Models\Treatment;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
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
use Illuminate\Support\Str;
use UnitEnum;

class TreatmentResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'tratamientos';

    protected static ?string $model = Treatment::class;

    protected static ?string $modelLabel = 'tratamiento';

    protected static ?string $pluralModelLabel = 'tratamientos';

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

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
                            ->helperText('Solo evaluaciones o tratamientos simples.'),
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
                    ->money('PEN')
                    ->placeholder('—'),
                IconColumn::make('online_bookable')
                    ->label('Reserva web')
                    ->boolean(),
                ToggleColumn::make('is_active')
                    ->label('Activo'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
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
