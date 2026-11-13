<?php

namespace App\Filament\Resources\Treatments;

use App\Enums\TreatmentKind;
use App\Enums\WebPresence;
use App\Filament\Concerns\HasSettingsMenuGroup;
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
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class TreatmentResource extends Resource
{
    use HasSettingsMenuGroup, HasSpanishLabels;

    protected static ?string $slug = 'tratamientos';

    protected static ?string $model = Treatment::class;

    protected static ?string $modelLabel = 'tratamiento';

    protected static ?string $pluralModelLabel = 'tratamientos';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 3;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    public static function settingsMenuGroup(): string
    {
        return 'Catálogo';
    }

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
                Toggle::make('is_active')
                    ->label('Activo')
                    ->default(true)
                    ->inline(false)
                    ->live()
                    ->helperText('Apagado, ya no sale al agendar ni en la web. Las citas ya dadas siguen igual.'),
                // Cuatro bloques, cada uno con un tema: antes los datos de la agenda, del precio y de la web iban mezclados.
                Section::make('Agenda')
                    ->description('Cuánto se reserva y con quién se puede agendar.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        TextInput::make('duration_minutes')
                            ->label('Duración de la cita')
                            ->suffix('min')
                            ->numeric()
                            ->minValue(5)
                            ->maxValue(480)
                            ->step(5)
                            ->default(30)
                            ->required()
                            ->helperText(fn (?Treatment $record) => self::durationHelp($record)),
                        TextInput::make('buffer_minutes')
                            ->label('Limpieza y esterilización')
                            ->suffix('min')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(60)
                            ->placeholder(fn () => (string) Setting::get('agenda.buffer_minutes', 10))
                            ->helperText(fn () => 'Vacío = el tiempo general del consultorio ('.Setting::get('agenda.buffer_minutes', 10).' min), que se cambia en Ajustes.'),
                        // Sin un doctor que lo atienda, el tratamiento no se puede agendar: se pregunta siempre, y
                        // uno nuevo viene con todos los doctores activos marcados.
                        CheckboxList::make('doctors')
                            ->label('¿Quién lo atiende?')
                            ->relationship('doctors', 'name', modifyQueryUsing: fn (Builder $query) => $query->active()->orderBy('sort_order'))
                            ->getOptionLabelFromRecordUsing(fn (Doctor $doctor) => $doctor->name.($doctor->accepts_online_booking ? '' : ' (no recibe reservas en línea)'))
                            ->default(fn () => Doctor::query()->active()->pluck('id')->all())
                            ->helperText(fn (Get $get) => self::whoAttendsHelp($get('doctors') ?? [], self::presenceOf($get)->isBookable()))
                            ->live()
                            ->columnSpanFull(),
                        // No tenía ninguna ayuda y cambia cómo se comporta la primera cita.
                        Toggle::make('is_evaluation')
                            ->label('Es una evaluación')
                            ->live()
                            ->helperText('Márcalo solo en la cita de revisión y diagnóstico. En la web va primero y es lo que se ofrece reservar cuando un tratamiento no se reserva en línea. En la historia, esa atención se abre como evaluación: motivo, examen y plan.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Precio')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        // El mismo nombre que en Paquetes: "Precio normal" es lo que suman sus tratamientos.
                        TextInput::make('price_from')
                            ->label('Precio normal')
                            ->prefix('S/')
                            ->numeric()
                            ->minValue(0)
                            ->helperText('El que proponen los presupuestos y suman los paquetes. En la web sale como "desde S/ …".'),
                        TextInput::make('sessions_label')
                            ->label('Sesiones que suele llevar')
                            ->placeholder('Por ejemplo: 2 a 3 sesiones')
                            ->maxLength(50)
                            ->helperText('En palabras: sale en la web y en esta lista.'),
                    ]),
                Section::make('Seguimiento y avisos')
                    ->description('Lo que el sistema hace solo con este tratamiento: cuándo llama a control, cuándo propone la sesión siguiente y qué le dice al paciente.')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        // Era una regla escondida: el tipo salía del nombre y, si el nombre no llevaba una de las
                        // palabras esperadas, no se aplicaba nada y nadie se enteraba.
                        Select::make('kind')
                            ->label('Tipo de tratamiento')
                            ->options(self::kinds())
                            ->placeholder(fn (Get $get) => 'Según el nombre: '.TreatmentKind::fromName($get('name'))->getLabel())
                            ->live()
                            ->visible(fn (Get $get) => ! $get('is_evaluation'))
                            ->helperText('De esto depende cada cuánto toca control, qué riesgos se revisan en el consentimiento, qué se anota en la atención y los textos que se sugieren aquí abajo. Sin elegir, el sistema lo deduce del nombre.')
                            ->columnSpanFull(),
                        TextInput::make('checkup_months')
                            ->label('Vuelve a control cada')
                            ->suffix('meses')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(36)
                            ->placeholder(fn (Get $get) => (string) self::kindOf($get)->checkupMonths())
                            ->helperText('Pasado ese tiempo sin cita, el paciente sale en "Les toca control". Vacío = lo usual para su tipo.'),
                        TextInput::make('next_session_days')
                            ->label('Sesión siguiente a los')
                            ->suffix('días')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(365)
                            ->placeholder(fn (Get $get) => (string) self::kindOf($get)->nextSessionDays())
                            ->helperText('La fecha que se propone al programar la sesión que sigue. Vacío = lo usual para su tipo.'),
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
                // Todo lo de la web en un solo bloque: antes la dirección y el resumen iban arriba, la página plegada
                // al medio y los interruptores abajo. Lo que solo sirve a la página se pide si va a aparecer en ella.
                Section::make('Página web')
                    ->columnSpanFull()
                    ->columns(2)
                    ->schema([
                        Radio::make('presence')
                            ->label('Dónde aparece')
                            ->options(fn (?Treatment $record) => collect(WebPresence::choices($record?->webPresence()))
                                ->mapWithKeys(fn (WebPresence $presence) => [$presence->value => $presence->getLabel()])
                                ->all())
                            ->descriptions([
                                WebPresence::Clinic->value => 'Se agenda desde el panel. No sale en la página web.',
                                WebPresence::Web->value => 'Tiene su página en la web. La cita se pide por WhatsApp o en el consultorio.',
                                WebPresence::Booking->value => 'Además, el paciente lo puede reservar como primera cita. Solo evaluaciones o tratamientos simples.',
                                WebPresence::BookingOnly->value => 'Como estaba: se reserva en la web, pero no tiene página propia.',
                            ])
                            ->default(WebPresence::Web->value)
                            ->afterStateHydrated(fn (Radio $component, ?Treatment $record) => $record ? $component->state($record->webPresence()->value) : null)
                            ->required()
                            ->live()
                            ->helperText(fn (Get $get) => $get('is_active') ? null : new HtmlString('<span class="help-warning">Está inactivo: no aparece en ningún lado. Al activarlo, vuelve a aparecer así.</span>'))
                            ->columnSpanFull(),
                        TextInput::make('slug')
                            ->label('Dirección en la web')
                            ->prefix('/tratamientos/')
                            ->required()
                            ->unique(ignoreRecord: true)
                            ->alphaDash()
                            ->helperText('Sale sola del nombre. Cámbiala solo si quieres otra.')
                            ->visible(fn (Get $get) => self::presenceOf($get) !== WebPresence::Clinic),
                        Toggle::make('show_price')
                            ->label('Mostrar el precio en la web')
                            ->default(true)
                            ->inline(false)
                            ->visible(fn (Get $get) => self::presenceOf($get) !== WebPresence::Clinic),
                        Textarea::make('summary')
                            ->label('Resumen')
                            ->rows(2)
                            ->maxLength(500)
                            ->helperText('Una o dos frases: sale en la lista de tratamientos de la web.')
                            ->visible(fn (Get $get) => self::presenceOf($get)->isPublic())
                            ->columnSpanFull(),
                        Section::make('Su página: descripción, foto y cómo sale en Google')
                            ->columnSpanFull()
                            ->collapsed()
                            ->compact()
                            ->visible(fn (Get $get) => self::presenceOf($get)->isPublic())
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
                    ]),
            ]);
    }

    /**
     * Lo elegido en "Dónde aparece" pasa a lo que el sistema guarda: si sale en la página y si se reserva en línea.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withPresence(array $data): array
    {
        $presence = WebPresence::tryFrom((string) Arr::pull($data, 'presence'));

        return $presence === null ? $data : [...$data, 'is_public' => $presence->isPublic(), 'online_bookable' => $presence->isBookable()];
    }

    /** Lo marcado en "Dónde aparece" mientras se llena la ficha. */
    private static function presenceOf(Get $get): WebPresence
    {
        return WebPresence::tryFrom((string) $get('presence')) ?? WebPresence::Clinic;
    }

    /**
     * Bajo "Duración de la cita": qué se reserva de verdad. La general vale para todos, salvo los doctores que
     * tienen la suya (puesta en su ficha) o a los que el sistema ya les midió lo que suelen tardar.
     */
    private static function durationHelp(?Treatment $record): string
    {
        $own = collect($record?->ownDurations() ?? [])
            ->map(fn (array $duration) => "{$duration['doctor']} se le reservan {$duration['minutes']} min ({$duration['source']})");

        return $own->isEmpty()
            ? 'Es lo que se reserva con cualquier doctor. Cada doctor puede tener su propia duración, en su ficha.'
            : 'A '.$own->join('; a ', ' y a ').'. Con los demás doctores se reserva esta duración.';
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
            $chosen->contains(true) => 'Aparece en la reserva de la web con los doctores marcados que reciben reservas en línea.',
            default => new HtmlString('<span class="help-warning">Así no aparece en la reserva de la web: marca al menos un doctor que reciba reservas en línea.</span>'),
        };
    }

    /** El tipo de tratamiento con lo que hay en pantalla: el elegido o, sin elegir, el que sale del nombre. */
    private static function kindOf(Get $get): TreatmentKind
    {
        return TreatmentKind::for(new Treatment(['name' => (string) $get('name'), 'kind' => TreatmentKind::tryFrom((string) $get('kind')), 'is_evaluation' => (bool) $get('is_evaluation')]));
    }

    /**
     * Los tipos que se pueden elegir. "Evaluación" no va en la lista: se marca con su interruptor.
     *
     * @return array<string, string>
     */
    private static function kinds(): array
    {
        return collect(TreatmentKind::cases())
            ->reject(fn (TreatmentKind $kind) => $kind === TreatmentKind::Evaluation)
            ->mapWithKeys(fn (TreatmentKind $kind) => [$kind->value => $kind->getLabel()])
            ->all();
    }

    /** En la lista, quién lo atiende: los doctores activos marcados en su ficha. */
    private static function whoAttends(Treatment $record): ?string
    {
        $attending = $record->doctors->where('is_active', true)->sortBy('sort_order')->pluck('name');

        return match (true) {
            $attending->isEmpty() => null,
            $attending->count() > 1 && $attending->count() === Doctor::query()->active()->count() => 'Todos los doctores',
            default => $attending->join(', ', ' y '),
        };
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
                    // Su tipo, que antes no se veía en ningún lado, y las sesiones que suele llevar.
                    ->description(fn (Treatment $record) => $record->is_active && ! $record->has_doctor
                        ? new HtmlString(e(self::typeAndSessions($record)).' <span class="help-warning">Nadie lo atiende: no se puede agendar. Edítalo y marca quién lo atiende.</span>')
                        : self::typeAndSessions($record))
                    ->wrap(),
                TextColumn::make('attended_by')
                    ->label('Quién lo atiende')
                    ->state(fn (Treatment $record) => self::whoAttends($record))
                    ->placeholder('Nadie')
                    ->wrap(),
                // El tiempo que de verdad se usa y de dónde viene: la limpieza propia o la general, y los doctores
                // a los que se les reserva otra duración.
                TextColumn::make('duration_minutes')
                    ->label('Duración de la cita')
                    ->suffix(' min')
                    ->description(fn (Treatment $record) => collect([
                        '+'.$record->bufferMinutes().' de limpieza'.($record->buffer_minutes === null ? ' (la general)' : ' (propia)'),
                        ...array_map(fn (array $duration) => "{$duration['doctor']}: {$duration['minutes']} min", $record->ownDurations()),
                    ])->implode(' · '))
                    ->wrap(),
                TextColumn::make('price_from')
                    ->label('Precio normal')
                    ->money('PEN', locale: 'es_PE')
                    ->placeholder('—'),
                // En palabras, no con una X roja que parecía un error. Marcado para la reserva pero sin un doctor que
                // lo atienda por la web: no aparece en la reserva, y se avisa.
                TextColumn::make('presence')
                    ->label('Dónde aparece')
                    ->state(fn (Treatment $record) => $record->is_active ? $record->webPresence()->shortLabel() : null)
                    ->placeholder('—')
                    ->color(fn (Treatment $record) => match (true) {
                        self::missesWebDoctor($record) => 'warning',
                        $record->webPresence() === WebPresence::Clinic => 'gray',
                        default => null,
                    })
                    ->icon(fn (Treatment $record) => self::missesWebDoctor($record) ? Heroicon::OutlinedExclamationTriangle : null)
                    ->description(fn (Treatment $record) => self::missesWebDoctor($record)
                        ? 'No aparece en la reserva: ningún doctor que reciba reservas en línea lo atiende.'
                        : null)
                    ->wrap(),
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
            ->defaultPaginationPageOption(25)
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['supplies', 'doctors'])
                ->withExists([
                    'doctors as has_doctor' => fn (Builder $doctors) => $doctors->active(),
                    'doctors as has_web_doctor' => fn (Builder $doctors) => $doctors->active()->where('accepts_online_booking', true),
                ]))
            ->recordActions([
                TreatmentRecipeAction::make(),
                EditAction::make()
                    ->iconButton()
                    ->tooltip('Editar')
                    ->mutateDataUsing(fn (array $data) => self::withPresence($data))
                    ->successNotificationTitle('Tratamiento guardado'),
                // Borrar va en el menú para que la fila entre entera. Con citas o cobros no se borra: avisa que se apaga "Activo".
                ActionGroup::make([
                    // Para uno parecido a otro que ya está: ahorra volver a llenar toda la ficha.
                    Action::make('duplicate')
                        ->label('Duplicar')
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->visible(fn (Treatment $record) => ! $record->trashed() && auth()->user()->can('create', Treatment::class))
                        ->modalHeading(fn (Treatment $record) => 'Duplicar "'.$record->name.'"')
                        ->modalDescription('La copia lleva los mismos tiempos, precio, seguimiento, doctores y materiales. Queda solo para el consultorio: después eliges en su ficha dónde aparece.')
                        ->modalWidth('md')
                        ->schema([
                            TextInput::make('name')
                                ->label('Nombre del nuevo tratamiento')
                                ->default(fn (Treatment $record) => $record->name.' (copia)')
                                ->required()
                                ->maxLength(100),
                        ])
                        ->modalSubmitActionLabel('Duplicar')
                        ->action(function (Treatment $record, array $data): void {
                            $copy = $record->duplicateAs($data['name']);

                            Notification::make()->success()->title('Tratamiento duplicado')->body('"'.$copy->name.'" quedó solo para el consultorio. Revisa su ficha y elige dónde aparece.')->send();
                        }),
                    DeleteAction::make()
                        ->authorizationNotification()
                        ->modalDescription('Solo se borra lo que nunca se usó. Lo que ya tiene citas, cobros o está en un paquete se apaga con "Activo". Se puede restaurar.')
                        ->successNotificationTitle('Tratamiento borrado'),
                    RestoreAction::make()
                        ->successNotificationTitle('Tratamiento restaurado'),
                ]),
            ]);
    }

    /** "Limpieza · 1 sesión": el tipo de tratamiento y, si lo dice su ficha, las sesiones que suele llevar. */
    private static function typeAndSessions(Treatment $record): string
    {
        return collect([TreatmentKind::for($record)->getLabel(), $record->sessionsText()])->filter()->implode(' · ');
    }

    /** Activo y marcado para la reserva en línea, pero sin un doctor que la reciba: en la web no se puede reservar. */
    private static function missesWebDoctor(Treatment $record): bool
    {
        return $record->is_active && $record->webPresence()->isBookable() && ! $record->has_web_doctor;
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
