<?php

namespace App\Filament\Resources\ConsentTemplates;

use App\Filament\Concerns\HasSettingsMenuGroup;
use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\ConsentTemplates\Pages\ManageConsentTemplates;
use App\Models\AuditLog;
use App\Models\ConsentTemplate;
use App\Models\Treatment;
use App\Services\Clinical\ConsentService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\HtmlString;

/** Textos de consentimiento informado. Uno general y, si hace falta, uno por tratamiento. */
class ConsentTemplateResource extends Resource
{
    use HasSettingsMenuGroup, HasSpanishLabels;

    protected static ?string $slug = 'consentimientos';

    protected static ?string $model = ConsentTemplate::class;

    protected static ?string $modelLabel = 'texto de consentimiento';

    protected static ?string $pluralModelLabel = 'textos de consentimiento';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 8;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    public static function settingsMenuGroup(): string
    {
        return 'Textos';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->label('Nombre')
                    ->required()
                    ->maxLength(255),
                Toggle::make('is_active')
                    ->label('En uso')
                    ->default(true)
                    ->helperText('Apagado, ya no se propone al firmar. Lo ya firmado no cambia.'),
                Select::make('treatment_id')
                    ->label('Tratamiento')
                    ->placeholder('General (para cualquier tratamiento)')
                    ->options(fn () => Treatment::query()->active()->orderBy('sort_order')->pluck('name', 'id'))
                    ->live(),
                Textarea::make('body')
                    ->label('Texto')
                    ->rows(14)
                    ->required()
                    ->live(onBlur: true)
                    ->helperText('Puedes usar: '.collect(ConsentService::PLACEHOLDERS)->map(fn (string $help, string $key) => "{$key} {$help}")->implode(' · ').'. Cambiar el texto no toca los consentimientos ya firmados.'),
                // El texto lleva datos que se llenan solos y no se veía cómo quedaba hasta usarlo con un paciente.
                Section::make('Así queda al firmar')
                    ->description('Con datos de ejemplo, para revisarlo antes de guardar. El párrafo de los riesgos sale solo cuando el paciente los tiene por sus antecedentes.')
                    ->compact()
                    ->visible(fn (Get $get) => filled($get('body')))
                    ->schema([
                        Text::make(fn (Get $get) => new HtmlString(nl2br(e(app(ConsentService::class)->preview((string) $get('body'), Treatment::query()->find($get('treatment_id'))))))),
                    ]),
            ]);
    }

    /**
     * Lo que decía cada versión anterior, de la más reciente a la más antigua. Sale del historial de cambios: cada
     * vez que se cambia el texto queda guardado el de antes, con su número de versión.
     *
     * @return list<array{number: int, until: string, by: string, body: string}>
     */
    public static function previousVersions(ConsentTemplate $template): array
    {
        $changes = $template->audits()
            ->with('user')
            ->where('event', 'updated')
            ->get()
            ->filter(fn (AuditLog $change) => filled($change->old_values['body'] ?? null))
            ->values();

        return $changes
            ->map(fn (AuditLog $change, int $index) => [
                // La más antigua es la versión 1: sirve cuando el registro no trae el número.
                'number' => (int) ($change->old_values['version'] ?? $changes->count() - $index),
                'until' => $change->created_at->format('d/m/Y'),
                'by' => $change->user?->name ?? 'el sistema',
                'body' => (string) $change->old_values['body'],
            ])
            ->all();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->modifyQueryUsing(fn ($query) => $query->with('treatment'))
            ->columns([
                // Con dos textos en uso para lo mismo, se dice cuál se propone al firmar y cuál hay que elegir a mano.
                TextColumn::make('name')
                    ->label('Nombre')
                    ->weight('medium')
                    ->description(fn (ConsentTemplate $record) => match (app(ConsentService::class)->isProposedFirst($record)) {
                        true => 'Hay otro en uso para lo mismo: al firmar se propone este, el más reciente.',
                        false => 'Hay otro más reciente en uso para lo mismo: este solo sale si se elige al firmar.',
                        null => null,
                    })
                    ->wrap(),
                TextColumn::make('treatment.name')
                    ->label('Tratamiento')
                    ->placeholder('General'),
                TextColumn::make('version')
                    ->label('Versión')
                    ->prefix('v'),
                ToggleColumn::make('is_active')
                    ->label('En uso')
                    ->disabled(fn () => ! auth()->user()->isAdmin())
                    ->afterStateUpdated(fn (ConsentTemplate $record, mixed $state) => Notification::make()
                        ->success()
                        ->title('"'.$record->name.'" '.($state ? 'queda en uso' : 'ya no se usa'))
                        ->body($state ? null : 'Los consentimientos ya firmados no cambian.')
                        ->send()),
            ])
            ->recordActions([
                // Los doctores no redactan los textos, pero los firman con el paciente: pueden leerlos.
                ViewAction::make()
                    ->label('Ver')
                    ->modalHeading(fn (ConsentTemplate $record) => $record->name)
                    ->visible(fn (ConsentTemplate $record) => ! auth()->user()->can('update', $record)),
                EditAction::make()
                    ->successNotificationTitle('Texto de consentimiento guardado'),
                // Solo se veía el número de versión: ahora, lo que decía cada una.
                Action::make('versions')
                    ->label('Versiones anteriores')
                    ->icon(Heroicon::OutlinedClock)
                    ->color('gray')
                    ->visible(fn (ConsentTemplate $record) => $record->version > 1)
                    ->modalHeading(fn (ConsentTemplate $record) => 'Versiones anteriores de "'.$record->name.'"')
                    ->modalContent(fn (ConsentTemplate $record) => view('filament.settings.consent-versions', ['versions' => self::previousVersions($record)]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Cerrar'),
                // La misma regla que en el resto de Configuración: se borra solo el texto con el que nunca se firmó.
                ActionGroup::make([
                    DeleteAction::make()
                        ->authorizationNotification()
                        ->modalDescription('Solo se borra un texto con el que nunca se firmó. Los demás se apagan con "En uso".')
                        ->successNotificationTitle('Texto de consentimiento borrado'),
                ])->visible(fn () => auth()->user()->isAdmin()),
            ])
            ->defaultPaginationPageOption(25);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageConsentTemplates::route('/'),
        ];
    }
}
