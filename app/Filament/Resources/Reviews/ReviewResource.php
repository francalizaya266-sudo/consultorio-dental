<?php

namespace App\Filament\Resources\Reviews;

use App\Enums\ReviewSource;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Reviews\Pages\ManageReviews;
use App\Models\Review;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

/** Opiniones de pacientes copiadas de Google (u otra fuente) para mostrarlas en la web. */
class ReviewResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'opiniones';

    protected static ?string $model = Review::class;

    protected static ?string $modelLabel = 'opinión';

    protected static ?string $pluralModelLabel = 'opiniones';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 9;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Text::make('Copia opiniones reales tal como las escribió el paciente. En la web sale solo su nombre y la inicial del apellido.')
                    ->columnSpanFull(),
                TextInput::make('author_name')
                    ->label('Nombre del paciente')
                    ->required()
                    ->maxLength(80),
                Select::make('rating')
                    ->label('Estrellas')
                    ->options([5 => '★★★★★ (5)', 4 => '★★★★ (4)', 3 => '★★★ (3)', 2 => '★★ (2)', 1 => '★ (1)'])
                    ->default(5)
                    ->required(),
                Textarea::make('body')
                    ->label('Opinión')
                    ->required()
                    ->rows(4)
                    ->maxLength(1500)
                    ->columnSpanFull(),
                Select::make('source')
                    ->label('De dónde viene')
                    ->options(ReviewSource::class)
                    ->default(ReviewSource::Google)
                    ->required(),
                DatePicker::make('reviewed_on')
                    ->label('Fecha')
                    ->maxDate(today()),
                TextInput::make('url')
                    ->label('Enlace a la opinión')
                    ->url()
                    ->maxLength(255),
                Select::make('treatment_id')
                    ->label('Tratamiento')
                    ->relationship('treatment', 'name')
                    ->searchable()
                    ->preload()
                    ->helperText('Opcional: también sale en la página de ese tratamiento.'),
                Toggle::make('is_published')
                    ->label('Publicada')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('author_name')
                    ->label('Paciente')
                    ->searchable()
                    ->description(fn (Review $record) => str_repeat('★', $record->rating)),
                TextColumn::make('body')
                    ->label('Opinión')
                    ->limit(90)
                    ->wrap(),
                TextColumn::make('source')
                    ->label('Fuente')
                    ->badge(),
                TextColumn::make('reviewed_on')
                    ->label('Fecha')
                    ->date('d/m/Y')
                    ->placeholder('—'),
                ToggleColumn::make('is_published')
                    ->label('Publicada'),
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
            ])
            ->emptyStateHeading('Todavía no hay opiniones')
            ->emptyStateDescription('Copia aquí las mejores opiniones de su perfil de Google. La web también enlaza a ese perfil (Ajustes → Web).');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageReviews::route('/'),
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
