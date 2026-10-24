<?php

namespace App\Filament\Resources\TextSnippets;

use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\TextSnippets\Pages\ManageTextSnippets;
use App\Models\TextSnippet;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

/**
 * Frases para escribir rápido en la historia clínica. Las del equipo las maneja
 * el administrador; cada doctor tiene las suyas y puede proponerlas para todos.
 */
class TextSnippetResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'opciones-rapidas';

    protected static ?string $model = TextSnippet::class;

    protected static ?string $modelLabel = 'opción rápida';

    protected static ?string $pluralModelLabel = 'opciones rápidas';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 5;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('label')
                    ->label('Nombre corto')
                    ->placeholder('Limpieza simple')
                    ->required()
                    ->maxLength(80),
                TextInput::make('shortcut')
                    ->label('Atajo')
                    ->prefix('/')
                    ->placeholder('limp')
                    ->alphaDash()
                    ->maxLength(20)
                    ->helperText('Escribe /atajo en la historia y se completa el texto.'),
                Select::make('field')
                    ->label('Dónde aparece')
                    ->options(SnippetField::class)
                    ->default(SnippetField::Any->value)
                    ->required(),
                Toggle::make('shared')
                    ->label('Compartida con todo el equipo')
                    ->default(true)
                    ->visible(fn () => auth()->user()->isAdmin())
                    ->afterStateHydrated(fn (Toggle $component, ?TextSnippet $record) => $component->state($record ? $record->isShared() : true))
                    ->inline(false),
                Textarea::make('body')
                    ->label('Texto')
                    ->rows(5)
                    ->required()
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('label')
                    ->label('Opción')
                    ->searchable(['label', 'body', 'shortcut'])
                    ->description(fn (TextSnippet $record) => str($record->body)->limit(90)),
                TextColumn::make('shortcut')
                    ->label('Atajo')
                    ->prefix('/')
                    ->placeholder('—'),
                TextColumn::make('field')
                    ->label('Dónde')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('user.name')
                    ->label('De')
                    ->placeholder('Equipo'),
                TextColumn::make('status')
                    ->label('Estado')
                    ->badge(),
            ])
            ->defaultSort('label')
            ->filters([
                SelectFilter::make('field')
                    ->label('Dónde aparece')
                    ->options(SnippetField::class),
            ])
            ->recordActions([
                Action::make('propose')
                    ->label('Proponer para todos')
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription('El administrador podrá sumarla a las opciones del equipo.')
                    ->visible(fn (TextSnippet $record) => $record->user_id === auth()->id() && $record->status === SnippetStatus::Active && ! auth()->user()->isAdmin())
                    ->authorize('update')
                    ->action(function (TextSnippet $record) {
                        $record->update(['status' => SnippetStatus::Proposed]);
                        Notification::make()->success()->title('Propuesta enviada')->send();
                    }),
                Action::make('approve')
                    ->label('Sumar al equipo')
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (TextSnippet $record) => $record->status === SnippetStatus::Proposed && auth()->user()->isAdmin())
                    ->authorize('approve')
                    ->action(function (TextSnippet $record) {
                        $record->update(['user_id' => null, 'status' => SnippetStatus::Active]);
                        Notification::make()->success()->title('Ahora la usa todo el equipo')->send();
                    }),
                EditAction::make()
                    ->mutateDataUsing(fn (array $data, TextSnippet $record) => self::withOwnership($data, $record)),
                DeleteAction::make(),
            ]);
    }

    /** Opciones del equipo y propias; el administrador ve además las propuestas. */
    public static function getEloquentQuery(): Builder
    {
        /** @var User $user */
        $user = auth()->user();

        return parent::getEloquentQuery()
            ->with('user')
            ->where(fn (Builder $q) => $q
                ->whereNull('user_id')
                ->orWhere('user_id', $user->getKey())
                ->when($user->isAdmin(), fn (Builder $w) => $w->orWhere('status', SnippetStatus::Proposed)));
    }

    /**
     * De quién es la opción según quién la guarda: el administrador elige; el resto, siempre propia.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withOwnership(array $data, ?TextSnippet $record = null): array
    {
        /** @var User $user */
        $user = auth()->user();
        $shared = (bool) Arr::pull($data, 'shared', $record?->isShared() ?? false);

        return ['user_id' => $user->isAdmin() && $shared ? null : $user->getKey()] + $data;
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTextSnippets::route('/'),
        ];
    }
}
