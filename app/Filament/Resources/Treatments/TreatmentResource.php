<?php

namespace App\Filament\Resources\Treatments;

use App\Enums\TreatmentKind;
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
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
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
                Section::make('Seguimiento y avisos')
                    ->description('Lo que el sistema hace solo con este tratamiento: cuándo llama a control, cuándo propone la sesión siguiente y qué le dice al paciente.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('checkup_months')
                            ->label('Vuelve a control cada')
                            ->suffix('meses')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(36)
                            ->placeholder(fn (Get $get) => (string) self::kindOf($get)->checkupMonths())
                            ->helperText('Pasado ese tiempo sin cita, el paciente sale en "Les toca control". Vacío = lo usual para este tratamiento.'),
                        TextInput::make('next_session_days')
                            ->label('Sesión siguiente a los')
                            ->suffix('días')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->placeholder(fn (Get $get) => (string) self::kindOf($get)->nextSessionDays())
                            ->helperText('La fecha que se propone al programar la sesión que sigue. Vacío = lo usual para este tratamiento.'),
                        Textarea::make('before_instructions')
                            ->label('Antes de venir')
                            ->rows(2)
                            ->maxLength(300)
                            ->placeholder('Por ejemplo: ven habiendo comido y trae tus radiografías.')
                            ->helperText('Sale solo en el recordatorio del día anterior. Vacío = el recordatorio va sin indicaciones.')
                            ->hintAction(self::suggestAction('before_instructions', fn (TreatmentKind $kind) => $kind->suggestedBeforeInstructions()))
                            ->columnSpanFull(),
                        Textarea::make('aftercare')
                            ->label('Cuidados después de la atención')
                            ->rows(5)
                            ->maxLength(1500)
                            ->placeholder("Por ejemplo:\n- Hoy no escupas ni te enjuagues.\n- Come blando y frío.")
                            ->helperText('Al terminar la cita, queda listo en Avisos para enviarlo por WhatsApp. Vacío = no se prepara ningún aviso.')
                            ->hintAction(self::suggestAction('aftercare', fn (TreatmentKind $kind) => $kind->suggestedAftercare()))
                            ->columnSpanFull(),
                    ]),
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
                    ->columnSpanFull()
                    ->schema([
                        // Sin un doctor que lo atienda, el tratamiento no se puede agendar: se pregunta siempre, y
                        // uno nuevo viene con todos los doctores activos marcados.
                        CheckboxList::make('doctors')
                            ->label('¿Quién lo atiende?')
                            ->relationship('doctors', 'name', modifyQueryUsing: fn (Builder $query) => $query->active()->orderBy('sort_order'))
                            ->getOptionLabelFromRecordUsing(fn (Doctor $doctor) => $doctor->name.($doctor->accepts_online_booking ? '' : ' (no recibe reservas por la web)'))
                            ->default(fn () => Doctor::query()->active()->pluck('id')->all())
                            ->helperText(fn (Get $get) => self::whoAttendsHelp($get('doctors') ?? [], (bool) $get('online_bookable')))
                            ->live()
                            ->columnSpanFull(),
                        Toggle::make('online_bookable')
                            ->label('Se reserva en la web como primera cita')
                            ->helperText('Solo evaluaciones o tratamientos simples.')
                            ->live(),
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

    /**
     * Bajo "¿Quién lo atiende?": qué pasa con lo marcado. Sin nadie no se puede agendar; y si se reserva en la
     * web, hace falta alguno que reciba reservas por la web.
     *
     * @param  array<int, int|string>  $doctorIds
     */
    private static function whoAttendsHelp(array $doctorIds, bool $onlineBookable): string|HtmlString
    {
        $chosen = Doctor::query()->active()->whereKey($doctorIds)->pluck('accepts_online_booking');

        return match (true) {
            $chosen->isEmpty() => new HtmlString('<span class="help-warning">Así no se puede agendar: marca al menos un doctor.</span>'),
            ! $onlineBookable => 'Al agendar este tratamiento se ofrecen solo los doctores marcados.',
            $chosen->contains(true) => 'Aparece en la reserva de la web con los doctores marcados que reciben reservas por la web.',
            default => new HtmlString('<span class="help-warning">Así no aparece en la reserva de la web: marca al menos un doctor que reciba reservas por la web.</span>'),
        };
    }

    /** El tipo de tratamiento según el nombre que hay en pantalla. */
    private static function kindOf(Get $get): TreatmentKind
    {
        return TreatmentKind::for(new Treatment(['name' => (string) $get('name'), 'is_evaluation' => (bool) $get('is_evaluation')]));
    }

    /**
     * "Usar texto sugerido": propone lo usual para ese tipo de tratamiento. Solo aparece con el campo vacío y
     * cuando hay algo que sugerir; el consultorio lo revisa y lo cambia antes de guardar.
     *
     * @param  Closure(TreatmentKind): ?string  $suggestion
     */
    private static function suggestAction(string $field, Closure $suggestion): Action
    {
        return Action::make('suggest_'.$field)
            ->label('Usar texto sugerido')
            ->link()
            ->visible(fn (Get $get) => blank($get($field)) && $suggestion(self::kindOf($get)) !== null)
            ->action(fn (Get $get, Set $set) => $set($field, $suggestion(self::kindOf($get))));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->stackedOnMobile()
            ->columns([
                TextColumn::make('name')
                    ->label('Tratamiento')
                    ->searchable()
                    // Activo pero sin doctor que lo atienda: en "Nueva cita" no habría con quién agendarlo, y se avisa.
                    ->description(fn (Treatment $record) => $record->is_active && ! $record->has_doctor
                        ? new HtmlString(trim(e((string) $record->sessionsText()).' <span class="help-warning">Nadie lo atiende: no se puede agendar. Edítalo y marca quién lo atiende.</span>'))
                        : $record->sessionsText())
                    ->wrap(),
                TextColumn::make('duration_minutes')
                    ->label('Duración')
                    ->suffix(' min')
                    ->description(fn (Treatment $record) => '+'.$record->bufferMinutes().' de limpieza'),
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
                    ->label('Activo')
                    ->afterStateUpdated(fn (Treatment $record, mixed $state) => Notification::make()
                        ->success()
                        ->title('"'.$record->name.'" '.($state ? 'vuelve a ofrecerse' : 'ya no se ofrece'))
                        ->body($state ? null : 'Ya no sale al agendar ni en la web. Las citas ya dadas y los paquetes que lo incluyen siguen igual.')
                        ->send()),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->filters([
                TrashedFilter::make(),
            ])
            ->modifyQueryUsing(fn (Builder $query) => $query->with('supplies')
                ->withExists([
                    'doctors as has_doctor' => fn (Builder $doctors) => $doctors->active(),
                    'doctors as has_web_doctor' => fn (Builder $doctors) => $doctors->active()->where('accepts_online_booking', true),
                ]))
            ->recordActions([
                TreatmentRecipeAction::make(),
                EditAction::make()->iconButton()->tooltip('Editar'),
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
