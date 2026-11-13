<?php

namespace App\Filament\Resources\TextSnippets;

use App\Enums\SnippetField;
use App\Enums\SnippetStatus;
use App\Filament\Concerns\HasSettingsMenuGroup;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\TextSnippets\Pages\ManageTextSnippets;
use App\Models\TextSnippet;
use App\Models\User;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

/**
 * Frases para escribir rápido en la historia clínica. Las del equipo las maneja
 * el administrador; cada doctor tiene las suyas y puede proponerlas para todos.
 */
class TextSnippetResource extends Resource
{
    use HasSettingsMenuGroup, HasSpanishLabels;

    protected static ?string $slug = 'opciones-rapidas';

    protected static ?string $model = TextSnippet::class;

    protected static ?string $modelLabel = 'opción rápida';

    protected static ?string $pluralModelLabel = 'opciones rápidas';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 6;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    public static function settingsMenuGroup(): string
    {
        return 'Textos';
    }

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
                    // Dos opciones con el mismo atajo: solo una se completaba al escribirlo. Se compara entre las
                    // del equipo o entre las propias; una propia sí puede repetir el atajo de una del equipo (gana la propia).
                    ->rule(fn (Get $get, ?TextSnippet $record) => function (string $attribute, mixed $value, Closure $fail) use ($get, $record): void {
                        if (self::shortcutIsTaken((string) $value, self::savesAsShared($get('shared'), $record), $record)) {
                            $fail('Ya hay otra opción con el atajo /'.$value.'. Usa uno distinto.');
                        }
                    })
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
            ->stackedOnMobile()
            ->columns([
                // De quién es y en qué estado está se dice solo cuando no es lo de siempre (del equipo y activa):
                // dos columnas que repetían "Equipo" y "Activa" en cada fila empujaban "Editar" fuera de la pantalla.
                TextColumn::make('label')
                    ->label('Opción')
                    ->searchable(['label', 'body', 'shortcut'])
                    ->formatStateUsing(fn (TextSnippet $record) => ($note = self::ownershipNote($record))
                        ? new HtmlString(e($record->label).' <span class="snip-note">'.e($note).'</span>')
                        : $record->label)
                    ->description(fn (TextSnippet $record) => str($record->body)->limit(90))
                    ->wrap(),
                TextColumn::make('shortcut')
                    ->label('Atajo')
                    ->prefix('/')
                    ->placeholder('—'),
                TextColumn::make('field')
                    ->label('Dónde')
                    ->badge()
                    ->color('gray'),
            ])
            ->defaultSort('label')
            ->filters([
                SelectFilter::make('field')
                    ->label('Dónde aparece')
                    ->options(SnippetField::class),
                // Las archivadas no estorban: se ven solo si se piden.
                TernaryFilter::make('archived')
                    ->label('Archivadas')
                    ->placeholder('Sin las archivadas')
                    ->trueLabel('Solo las archivadas')
                    ->falseLabel('Todas')
                    ->queries(
                        true: fn (Builder $query) => $query->where('status', SnippetStatus::Archived),
                        false: fn (Builder $query) => $query,
                        blank: fn (Builder $query) => $query->where('status', '!=', SnippetStatus::Archived),
                    ),
            ])
            ->recordActions([
                Action::make('propose')
                    ->label('Proponer para todos')
                    ->icon(Heroicon::OutlinedUserGroup)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Proponer esta opción para todo el equipo?')
                    ->modalDescription('El administrador podrá sumarla a las opciones del equipo.')
                    ->modalSubmitActionLabel('Proponer')
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
                    ->modalHeading('¿Sumar esta opción a las del equipo?')
                    ->modalDescription('Desde ahora la ve y la usa todo el equipo.')
                    ->modalSubmitActionLabel('Sumar al equipo')
                    ->visible(fn (TextSnippet $record) => $record->status === SnippetStatus::Proposed && auth()->user()->isAdmin())
                    ->authorize('approve')
                    ->action(function (TextSnippet $record) {
                        $record->update(['user_id' => null, 'status' => SnippetStatus::Active]);
                        Notification::make()->success()->title('Ahora la usa todo el equipo')->send();
                    }),
                EditAction::make()
                    ->mutateDataUsing(fn (array $data, TextSnippet $record) => self::withOwnership($data, $record))
                    ->successNotificationTitle('Opción rápida guardada'),
                // En el menú ⋮: archivar lo que ya no se usa (deja de ofrecerse, pero se conserva) y borrar lo que
                // nunca se usó. El estado "Archivada" existía, pero ninguna pantalla dejaba archivar.
                ActionGroup::make([
                    Action::make('archive')
                        ->label('Archivar')
                        ->icon(Heroicon::OutlinedArchiveBox)
                        ->requiresConfirmation()
                        ->modalHeading(fn (TextSnippet $record) => '¿Archivar "'.$record->label.'"?')
                        ->modalDescription('Deja de ofrecerse al escribir la historia. Queda en esta lista y se puede volver a usar.')
                        ->modalSubmitActionLabel('Archivar')
                        ->visible(fn (TextSnippet $record) => $record->status === SnippetStatus::Active)
                        ->authorize('archive')
                        ->action(function (TextSnippet $record) {
                            $record->update(['status' => SnippetStatus::Archived]);

                            Notification::make()->success()->title('"'.$record->label.'" archivada')->body('Ya no se ofrece al escribir la historia.')->send();
                        }),
                    Action::make('unarchive')
                        ->label('Volver a usar')
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->visible(fn (TextSnippet $record) => $record->status === SnippetStatus::Archived)
                        ->authorize('archive')
                        ->action(function (TextSnippet $record) {
                            $record->update(['status' => SnippetStatus::Active]);

                            Notification::make()->success()->title('"'.$record->label.'" se ofrece de nuevo')->send();
                        }),
                    DeleteAction::make()
                        ->authorizationNotification()
                        ->modalDescription('Solo se borra una opción que nunca se usó. Las que ya se usaron se archivan.')
                        ->successNotificationTitle('Opción rápida borrada'),
                ]),
            ])
            ->defaultPaginationPageOption(25);
    }

    /** Si al guardar la opción queda para todo el equipo (solo el administrador puede) o solo para quien la guarda. */
    private static function savesAsShared(mixed $shared, ?TextSnippet $record): bool
    {
        return auth()->user()->isAdmin() && (bool) ($shared ?? $record?->isShared() ?? false);
    }

    /** Otra opción del mismo dueño (el equipo, o quien guarda) ya usa ese atajo, sin mirar mayúsculas. */
    private static function shortcutIsTaken(string $shortcut, bool $shared, ?TextSnippet $record): bool
    {
        return TextSnippet::query()
            ->when($record, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
            ->whereRaw('lower(shortcut) = ?', [mb_strtolower($shortcut)])
            ->where(fn (Builder $query) => $shared ? $query->whereNull('user_id') : $query->where('user_id', auth()->id()))
            ->exists();
    }

    /** "Solo tuya", "Propuesta" o "Propuesta de Ana"; nada si es del equipo y está activa. */
    private static function ownershipNote(TextSnippet $record): ?string
    {
        $mine = $record->user_id === auth()->id();

        return match (true) {
            $record->status === SnippetStatus::Proposed => $mine ? 'Propuesta' : 'Propuesta de '.$record->user?->name,
            $record->status === SnippetStatus::Archived => 'Archivada',
            $mine => 'Solo tuya',
            $record->user_id !== null => 'De '.$record->user?->name,
            default => null,
        };
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
