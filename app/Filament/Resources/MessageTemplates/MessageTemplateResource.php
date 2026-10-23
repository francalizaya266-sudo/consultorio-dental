<?php

namespace App\Filament\Resources\MessageTemplates;

use App\Filament\Concerns\HasSpanishLabels;
use App\Filament\Resources\MessageTemplates\Pages\ManageMessageTemplates;
use App\Models\MessageTemplate;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Textos de los avisos de WhatsApp. Las palabras entre llaves se reemplazan solas. */
class MessageTemplateResource extends Resource
{
    use HasSpanishLabels;

    protected static ?string $slug = 'textos-whatsapp';

    protected static ?string $model = MessageTemplate::class;

    protected static ?string $modelLabel = 'texto de WhatsApp';

    protected static ?string $pluralModelLabel = 'textos de WhatsApp';

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 4;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleBottomCenterText;

    public const PLACEHOLDERS = '{nombre} quien recibe · {paciente} · {fecha} · {hora} · {doctor} · {tratamiento} · {precio} de la cita (con la campaña) · {consultorio} · {direccion} · {enlace} enlace personal · {receta} · {plan} y {total} del presupuesto · {cuota}, {plan} y {monto} en los avisos de cuota';

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('body')
                    ->label('Texto')
                    ->rows(8)
                    ->required()
                    ->helperText('Puedes usar: '.self::PLACEHOLDERS),
                Toggle::make('is_active')
                    ->label('Usar este texto')
                    ->helperText('Si lo desactivas, se usa el texto original del sistema.'),
                Section::make('API oficial de WhatsApp (etapa 2)')
                    ->description('Solo cuando el consultorio active la API de Meta.')
                    ->collapsed()
                    ->columns(2)
                    ->schema([
                        TextInput::make('api_template_name')
                            ->label('Nombre de la plantilla aprobada'),
                        TextInput::make('api_language')
                            ->label('Idioma')
                            ->default('es'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('key')
                    ->label('Aviso')
                    ->weight('medium'),
                TextColumn::make('body')
                    ->label('Texto')
                    ->limit(90)
                    ->wrap(),
                ToggleColumn::make('is_active')
                    ->label('En uso'),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading(fn (MessageTemplate $record) => $record->key->getLabel()),
                Action::make('reset')
                    ->label('Texto original')
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('¿Volver al texto original?')
                    ->modalDescription(fn (MessageTemplate $record) => $record->key->defaultBody())
                    ->authorize('update')
                    ->visible(fn (MessageTemplate $record) => $record->body !== $record->key->defaultBody())
                    ->action(function (MessageTemplate $record) {
                        $record->update(['body' => $record->key->defaultBody()]);
                        Notification::make()->success()->title('Texto original restaurado')->send();
                    }),
            ])
            ->paginated(false);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageMessageTemplates::route('/'),
        ];
    }
}
