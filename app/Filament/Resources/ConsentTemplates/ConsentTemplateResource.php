<?php

namespace App\Filament\Resources\ConsentTemplates;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\ConsentTemplates\Pages\ManageConsentTemplates;
use App\Models\ConsentTemplate;
use App\Models\Treatment;
use App\Services\Clinical\ConsentService;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Textos de consentimiento informado. Uno general y, si hace falta, uno por tratamiento. */
class ConsentTemplateResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'consentimientos';

    protected static ?string $model = ConsentTemplate::class;

    protected static ?string $modelLabel = 'texto de consentimiento';

    protected static ?string $pluralModelLabel = 'textos de consentimiento';

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                Select::make('treatment_id')
                    ->label('Tratamiento')
                    ->placeholder('General (para cualquier tratamiento)')
                    ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->pluck('name', 'id')),
                Textarea::make('body')
                    ->label('Texto')
                    ->rows(14)
                    ->required()
                    ->helperText('Puedes usar: '.collect(ConsentService::PLACEHOLDERS)->map(fn (string $help, string $key) => "{$key} {$help}")->implode(' · ').'. Cambiar el texto no toca los consentimientos ya firmados.'),
                Toggle::make('is_active')
                    ->label('En uso')
                    ->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('treatment'))
            ->columns([
                TextColumn::make('name')
                    ->label('Nombre')
                    ->weight('medium'),
                TextColumn::make('treatment.name')
                    ->label('Tratamiento')
                    ->placeholder('General'),
                TextColumn::make('version')
                    ->label('Versión')
                    ->prefix('v'),
                ToggleColumn::make('is_active')
                    ->label('En uso')
                    ->disabled(fn () => ! auth()->user()->isAdmin()),
            ])
            ->headerActions([
                CreateAction::make()->label('Nuevo texto')->createAnother(false),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageConsentTemplates::route('/'),
        ];
    }
}
