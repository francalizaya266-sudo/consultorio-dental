<?php

namespace App\Filament\Resources\Faqs;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\Faqs\Pages\ManageFaqs;
use App\Models\Faq;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use UnitEnum;

/** Preguntas frecuentes: las generales salen en su página y en el inicio; las de un tratamiento, en la página de ese tratamiento. */
class FaqResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'preguntas';

    protected static ?string $model = Faq::class;

    protected static ?string $modelLabel = 'pregunta frecuente';

    protected static ?string $pluralModelLabel = 'preguntas frecuentes';

    protected static string|UnitEnum|null $navigationGroup = 'Web';

    protected static ?int $navigationSort = 2;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQuestionMarkCircle;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('question')
                    ->label('Pregunta')
                    ->required()
                    ->maxLength(255),
                Textarea::make('answer')
                    ->label('Respuesta')
                    ->required()
                    ->rows(5)
                    ->helperText('Clara y corta. Para separar párrafos, deja una línea en blanco.'),
                Select::make('treatment_id')
                    ->label('Tratamiento')
                    ->relationship('treatment', 'name')
                    ->searchable()
                    ->preload()
                    ->placeholder('General')
                    ->helperText('Vacío = pregunta general. Con tratamiento, sale en la página de ese tratamiento.'),
                Toggle::make('is_published')
                    ->label('Publicada')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('question')
                    ->label('Pregunta')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('treatment.name')
                    ->label('Tratamiento')
                    ->placeholder('General'),
                ToggleColumn::make('is_published')
                    ->label('Publicada'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->filters([
                SelectFilter::make('treatment_id')
                    ->label('Tratamiento')
                    ->relationship('treatment', 'name'),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
            ])
            ->emptyStateHeading('Todavía no hay preguntas')
            ->emptyStateDescription('Agrega las dudas que más escuchan en recepción: precios, formas de pago, si atienden niños, estacionamiento…');
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageFaqs::route('/'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with('treatment')
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }
}
