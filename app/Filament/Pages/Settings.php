<?php

namespace App\Filament\Pages;

use App\Enums\MessageChannel;
use App\Filament\Concerns\HasSettingsMenuGroup;
use App\Filament\Pages\Concerns\EditsSettings;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Models\Setting;
use App\Services\WhatsApp\WhatsAppOutbox;
use App\Support\Dates;
use App\Support\Phone;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

/**
 * Datos del consultorio, reglas de la agenda y de las reservas web, las horas de los avisos y lo de dinero
 * (presupuestos, meta y reportes).
 * Lo de la página web va en Página web › Puesta a punto. Lo que se guarda aquí reemplaza los valores de
 * config/consultorio.php.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    use EditsSettings, HasSettingsMenuGroup;

    /** Cada cuántos minutos se pueden ofrecer horarios: valores que caen en horas redondas. */
    public const SLOT_STEPS = [5, 10, 15, 20, 30, 60];

    /**
     * Con cuánta anticipación mínima se reserva en la web. Se guarda en minutos, pero se elige con palabras:
     * antes se escribía en minutos (hasta 2880) mientras las demás reglas iban en horas o días.
     */
    public const LEAD_TIMES = [
        0 => 'Sin anticipación',
        30 => '30 minutos antes',
        60 => '1 hora antes',
        120 => '2 horas antes',
        180 => '3 horas antes',
        240 => '4 horas antes',
        360 => '6 horas antes',
        720 => '12 horas antes',
        1440 => '1 día antes',
        2880 => '2 días antes',
    ];

    protected static ?string $title = 'Ajustes';

    protected static ?string $slug = 'ajustes';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    public static function settingsMenuGroup(): string
    {
        return 'Consultorio';
    }

    protected static function settingKeys(): array
    {
        return [
            'clinic.name' => 'string',
            'clinic.whatsapp' => 'phone',
            'clinic.phone' => 'string',
            'clinic.address' => 'string',
            'clinic.district' => 'string',
            'clinic.email' => 'string',
            'clinic.slogan' => 'string',
            'clinic.legal_name' => 'string',
            'clinic.ruc' => 'string',
            'agenda.buffer_minutes' => 'int',
            'agenda.slot_step_minutes' => 'int',
            'agenda.min_useful_gap_minutes' => 'int',
            'booking.min_lead_minutes' => 'int',
            'booking.horizon_days' => 'int',
            'booking.max_web_appointments_per_patient' => 'int',
            'booking.max_web_appointments_per_phone' => 'int',
            'booking.self_reschedule_min_hours' => 'int',
            'booking.self_cancel_min_hours' => 'int',
            'booking.max_reschedule_options' => 'int',
            'reminders.day_before_at' => 'string',
            'reminders.flag_unconfirmed_at' => 'string',
            'reminders.installment_days_before' => 'int',
            'plans.valid_days' => 'int',
            'reports.doctors_see_own' => 'bool',
            'reports.monthly_goal' => 'optional_int',
        ];
    }

    protected function savedMessage(): string
    {
        return 'Ajustes guardados';
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Pestañas en vez de una sola hoja larga: cada tema a un clic y la pestaña queda en la dirección.
                Tabs::make('Ajustes')
                    ->contained(false)
                    ->persistTabInQueryString()
                    ->tabs([
                        Tab::make('Consultorio')
                            ->icon(Heroicon::OutlinedBuildingOffice2)
                            ->schema([
                                Section::make('Consultorio')
                                    // Lo del consultorio que solo usa la página web (logo, mapa, redes y opiniones) se queda
                                    // en Página web › Puesta a punto: desde aquí se llega con un enlace.
                                    ->description('El nombre, los teléfonos y la dirección salen en la web, en los avisos y en las hojas impresas.')
                                    ->headerActions([
                                        Action::make('webSetup')
                                            ->label('Logo, mapa, redes y opiniones: en Página web')
                                            ->link()
                                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                                            ->url(fn () => WebSetup::getUrl()),
                                    ])
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('clinic.name')
                                            ->label('Nombre')
                                            ->required()
                                            ->maxLength(100),
                                        TextInput::make('clinic.whatsapp')
                                            ->label('WhatsApp del consultorio')
                                            ->tel()
                                            ->required()
                                            ->rule(fn () => function (string $attribute, $value, $fail) {
                                                if (($problem = Phone::problem((string) $value)) !== null) {
                                                    $fail($problem);
                                                }
                                            }),
                                        TextInput::make('clinic.phone')
                                            ->label('Teléfono fijo')
                                            ->tel()
                                            ->maxLength(20)
                                            // Sale en la web ("Llamar al …") y en las hojas impresas: solo números y los signos de un teléfono.
                                            ->regex('/^[+(]?[0-9][0-9 ()\-]{5,18}$/')
                                            ->validationMessages(['regex' => 'Escribe solo el número, por ejemplo 051 321456.']),
                                        TextInput::make('clinic.address')
                                            ->label('Dirección')
                                            ->helperText('Sale en la web, en el mapa y en las hojas impresas. En los textos de aviso se pone con {direccion}.'),
                                        TextInput::make('clinic.district')
                                            ->label('Distrito o ciudad')
                                            ->placeholder('Juliaca')
                                            ->helperText('Para aparecer en Google como "dentista en …".'),
                                        TextInput::make('clinic.email')
                                            ->label('Correo')
                                            ->email(),
                                        TextInput::make('clinic.slogan')
                                            ->label('Lema')
                                            ->maxLength(80)
                                            ->helperText('Sale al pie de las recetas.'),
                                        // Para las hojas impresas (presupuestos, recibos, recetas y consentimientos), bajo el nombre.
                                        TextInput::make('clinic.legal_name')
                                            ->label('Razón social')
                                            ->maxLength(120)
                                            ->helperText('Opcional. Sale en las hojas impresas, junto al RUC.'),
                                        TextInput::make('clinic.ruc')
                                            ->label('RUC')
                                            ->regex('/^\d{11}$/')
                                            ->validationMessages(['regex' => 'El RUC tiene 11 números, sin espacios ni guiones.'])
                                            ->helperText('Opcional. Sale en las hojas impresas.'),
                                    ]),
                            ]),
                        Tab::make('Agenda y reservas')
                            ->icon(Heroicon::OutlinedCalendarDays)
                            ->schema([
                                Section::make('Agenda')
                                    ->headerActions([self::recommendedAction('recommendedAgenda', ['agenda.buffer_minutes', 'agenda.slot_step_minutes', 'agenda.min_useful_gap_minutes'])])
                                    ->columns(2)
                                    ->schema([
                                        self::minutes('agenda.buffer_minutes', 'Limpieza y esterilización entre citas', 0, 60)
                                            ->helperText('Cada tratamiento puede tener su propio tiempo.'),
                                        // Una lista en vez de un número libre: con "cada 7 minutos" los horarios salían a horas raras.
                                        Select::make('agenda.slot_step_minutes')
                                            ->label('Cada cuánto se ofrecen horarios')
                                            ->options(collect(self::SLOT_STEPS)->mapWithKeys(fn (int $minutes) => [$minutes => "Cada {$minutes} min"])->all())
                                            ->required()
                                            ->helperText('Cuando no hay citas cerca. Los horarios pegados a otras citas se ofrecen primero.'),
                                        // Estaba fijo en 30 minutos y no se veía en ninguna pantalla.
                                        self::minutes('agenda.min_useful_gap_minutes', 'Hueco mínimo que sirve para una cita', 10, 120)
                                            ->helperText('Un espacio libre más corto entre dos citas se cuenta como tiempo perdido: la agenda ofrece primero los horarios que no lo dejan.'),
                                    ]),
                                Section::make('Reservas y cambios desde la web')
                                    ->headerActions([self::recommendedAction('recommendedBooking', [
                                        'booking.min_lead_minutes', 'booking.horizon_days', 'booking.max_web_appointments_per_patient', 'booking.max_web_appointments_per_phone',
                                        'booking.self_reschedule_min_hours', 'booking.self_cancel_min_hours', 'booking.max_reschedule_options',
                                    ])])
                                    ->columns(2)
                                    ->schema([
                                        // Las reglas dichas en una frase, que cambia al mover los valores: se ve el efecto antes de guardar.
                                        Text::make(fn (Get $get) => self::bookingSummary($get))
                                            ->weight(FontWeight::Medium)
                                            ->columnSpanFull(),
                                        // Cada casilla vale sola, pero juntas pueden dejar la web sin horarios o sin forma de
                                        // cambiar una cita: se comparan con los días que se puede reservar hacia adelante.
                                        Select::make('booking.min_lead_minutes')
                                            ->label('Anticipación mínima para reservar')
                                            // Un valor guardado antes que no está en la lista (90 minutos, por ejemplo) se conserva.
                                            ->options(fn () => self::leadTimes())
                                            ->selectablePlaceholder(false)
                                            ->required()
                                            ->live()
                                            ->helperText('La web no ofrece horarios que empiecen antes de ese tiempo.')
                                            ->rule(fn (Get $get) => self::lessThanTheHorizon($get, 60, 'Así la web no ofrece ningún horario: la anticipación mínima es mayor que los días que se puede reservar hacia adelante.')),
                                        self::number('booking.horizon_days', 'Hasta cuántos días adelante se puede reservar', 1, 180, 'días'),
                                        self::number('booking.max_web_appointments_per_patient', 'Citas web futuras por paciente', 1, 10, 'citas'),
                                        self::number('booking.max_web_appointments_per_phone', 'Citas web futuras por celular (toda la familia)', 1, 30, 'citas')
                                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                                if ((int) $value < (int) $get('booking.max_web_appointments_per_patient')) {
                                                    $fail('Debe ser igual o mayor que las citas por paciente: el celular cuenta las de toda la familia.');
                                                }
                                            }),
                                        self::number('booking.self_reschedule_min_hours', 'Cambio automático si faltan más de', 1, 168, 'horas')
                                            ->helperText('Con menos tiempo, el paciente propone horarios y ustedes aprueban.')
                                            ->rule(fn (Get $get) => self::lessThanTheHorizon($get, 1, 'Así ningún paciente puede cambiar su cita por su cuenta: son más horas que las que se puede reservar hacia adelante.')),
                                        self::number('booking.self_cancel_min_hours', 'El paciente puede cancelar por su cuenta hasta', 0, 168, 'horas antes de la cita')
                                            ->helperText('Con menos tiempo, tiene que escribir o llamar al consultorio.')
                                            ->rule(fn (Get $get) => self::lessThanTheHorizon($get, 1, 'Así ningún paciente puede cancelar por su cuenta: son más horas que las que se puede reservar hacia adelante.')),
                                        self::number('booking.max_reschedule_options', 'Horarios que puede proponer el paciente', 1, 5, 'opciones'),
                                    ]),
                            ]),
                        // Las horas de los avisos estaban fijas y solo las cambiaba un técnico.
                        Tab::make('Avisos')
                            ->icon(Heroicon::OutlinedBellAlert)
                            ->schema([
                                Section::make('Recordatorio de la cita')
                                    ->description('El día anterior a la cita (el último en que se atiende antes de ella).')
                                    ->headerActions([
                                        Action::make('messageTexts')
                                            ->label('Lo que dice cada aviso: en Textos de aviso')
                                            ->link()
                                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                                            ->url(fn () => MessageTemplateResource::getUrl('index'))
                                            ->visible(fn () => MessageTemplateResource::canAccess()),
                                    ])
                                    ->columns(2)
                                    ->schema([
                                        Select::make('reminders.day_before_at')
                                            ->label('El recordatorio queda listo a las')
                                            ->options(fn () => self::hours('reminders.day_before_at'))
                                            ->selectablePlaceholder(false)
                                            ->required()
                                            ->helperText('Desde esa hora aparece en Avisos, para enviarlo por WhatsApp. Los avisos de cuotas se preparan a la misma hora.'),
                                        Select::make('reminders.flag_unconfirmed_at')
                                            ->label('Si no confirma, pasa a "llamar" a las')
                                            ->options(fn () => self::hours('reminders.flag_unconfirmed_at'))
                                            ->selectablePlaceholder(false)
                                            ->required()
                                            ->helperText('Ese mismo día. La cita queda marcada para que recepción la llame.')
                                            ->rule(fn (Get $get) => function (string $attribute, mixed $value, Closure $fail) use ($get): void {
                                                if ((string) $value <= (string) $get('reminders.day_before_at')) {
                                                    $fail('Tiene que ser después del recordatorio: primero se le recuerda y después se revisa si confirmó.');
                                                }
                                            }),
                                    ]),
                                Section::make('Cuotas')
                                    ->columns(2)
                                    ->schema([
                                        self::number('reminders.installment_days_before', 'El aviso de una cuota se prepara', 1, 15, 'días antes de que venza')
                                            ->helperText('Queda en Avisos para enviarlo. Si lo que el paciente tiene a favor ya cubre la cuota, no se prepara.'),
                                    ]),
                                // Si los avisos salen solos o a mano no se cambia aquí, pero no se veía en ninguna pantalla.
                                Section::make('Cómo salen los avisos')
                                    ->schema([
                                        Text::make(fn () => self::sendingMode()),
                                    ]),
                            ]),
                        // "Presupuestos" era una sección con una sola casilla y "Reportes" una pestaña con dos: todo lo
                        // de dinero va junto.
                        Tab::make('Dinero')
                            ->icon(Heroicon::OutlinedBanknotes)
                            ->schema([
                                Section::make('Presupuestos')
                                    ->columns(2)
                                    ->schema([
                                        self::number('plans.valid_days', 'Un presupuesto vale por', 7, 365, 'días')
                                            ->helperText('Desde que se presenta. Sale en la hoja y en el WhatsApp: "vale hasta el …". Lo aceptado mantiene su precio.'),
                                    ]),
                                Section::make('Meta y reportes')
                                    ->columns(2)
                                    ->schema([
                                        TextInput::make('reports.monthly_goal')
                                            ->label('Meta de cobro del mes')
                                            ->prefix('S/')
                                            ->numeric()
                                            ->integer()
                                            ->minValue(1)
                                            ->maxValue(10_000_000)
                                            ->helperText('Opcional. En "Hoy", la administración ve cuánto falta y si el mes va a buen ritmo.'),
                                        Toggle::make('reports.doctors_see_own')
                                            ->label('Cada doctor ve sus propios ingresos y gráficos')
                                            ->helperText('Solo lo suyo: lo que atendió y lo que se cobró de sus citas. Recepción no ve reportes.')
                                            ->inline(false),
                                    ]),
                            ]),
                    ]),
            ])
            ->statePath('data');
    }

    /**
     * Regla para un tiempo que debe caber dentro de lo que se puede reservar hacia adelante.
     *
     * @param  int  $unitsPerHour  En qué viene el valor: 60 si son minutos, 1 si son horas.
     */
    private static function lessThanTheHorizon(Get $get, int $unitsPerHour, string $message): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($get, $unitsPerHour, $message): void {
            if ((int) $value >= (int) $get('booking.horizon_days') * 24 * $unitsPerHour) {
                $fail($message);
            }
        };
    }

    /**
     * La lista de anticipaciones. Si lo guardado no está en ella (90 minutos, puesto cuando se escribía a mano),
     * se suma para no cambiarlo sin que nadie lo pida.
     *
     * @return array<int, string>
     */
    private static function leadTimes(): array
    {
        $saved = (int) Setting::get('booking.min_lead_minutes', 120);

        return self::LEAD_TIMES + (isset(self::LEAD_TIMES[$saved]) ? [] : [$saved => "{$saved} minutos antes"]);
    }

    /**
     * Las horas del día que se pueden elegir para un aviso, cada media hora. Si lo guardado no está en la lista
     * ("09:15", puesto por un técnico), se suma para no cambiarlo sin que nadie lo pida.
     *
     * @return array<string, string>
     */
    private static function hours(string $key): array
    {
        $hours = [];

        for ($minutes = 6 * 60; $minutes <= 21 * 60; $minutes += 30) {
            $hours[] = sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        return collect([...$hours, substr((string) Setting::get($key), 0, 5)])
            ->filter()
            ->unique()
            ->sort()
            ->mapWithKeys(fn (string $hour) => [$hour => Dates::time($hour)])
            ->all();
    }

    /** Si los avisos de WhatsApp salen solos o se envían a mano desde el panel. */
    private static function sendingMode(): string
    {
        return app(WhatsAppOutbox::class)->channel() === MessageChannel::CloudApi
            ? 'Los avisos salen solos por la conexión oficial de WhatsApp, a la hora que les toca.'
            : 'Los avisos se envían a mano: quedan listos en Avisos y el panel abre WhatsApp con el mensaje ya escrito, solo falta presionar Enviar. Para que salgan solos hace falta la conexión oficial de WhatsApp, que instala un técnico.';
    }

    /** Las reglas de reserva en una frase, con los valores que hay en pantalla. */
    private static function bookingSummary(Get $get): string
    {
        $lead = (int) $get('booking.min_lead_minutes');
        $plural = fn (int $count, string $one, string $many): string => $count === 1 ? "1 {$one}" : "{$count} {$many}";
        $anticipation = match (true) {
            $lead <= 0 => 'hasta el último momento',
            $lead < 60 => "con al menos {$lead} minutos de anticipación",
            $lead % 1440 === 0 => 'con al menos '.$plural(intdiv($lead, 1440), 'día', 'días').' de anticipación',
            $lead % 60 === 0 => 'con al menos '.$plural(intdiv($lead, 60), 'hora', 'horas').' de anticipación',
            default => "con al menos {$lead} minutos de anticipación",
        };
        $cancel = (int) $get('booking.self_cancel_min_hours');

        return 'Así queda: un paciente puede reservar '.$anticipation.' y hasta '.$plural((int) $get('booking.horizon_days'), 'día', 'días').' adelante, con '
            .$plural((int) $get('booking.max_web_appointments_per_patient'), 'cita futura', 'citas futuras').' como máximo ('
            .(int) $get('booking.max_web_appointments_per_phone').' entre toda la familia). Cambia su cita por su cuenta si faltan más de '
            .$plural((int) $get('booking.self_reschedule_min_hours'), 'hora', 'horas').' y la cancela '
            .($cancel === 0 ? 'hasta el último momento.' : 'hasta '.$plural($cancel, 'hora', 'horas').' antes.');
    }

    /**
     * "Volver a los valores recomendados": pone en pantalla los valores con que viene el sistema, para salir de una
     * mala combinación sin llamar a nadie. No guarda: se revisa y se guarda con el botón de siempre.
     *
     * @param  list<string>  $keys
     */
    private static function recommendedAction(string $name, array $keys): Action
    {
        return Action::make($name)
            ->label('Volver a los valores recomendados')
            ->link()
            ->icon(Heroicon::OutlinedArrowUturnLeft)
            ->action(function (Set $set) use ($keys): void {
                foreach ($keys as $key) {
                    $set($key, config('consultorio.defaults.'.$key));
                }

                Notification::make()->success()->title('Valores recomendados puestos')->body('Revisa y guarda los cambios.')->send();
            });
    }

    private static function number(string $key, string $label, int $min, int $max, string $suffix): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            ->numeric()
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->suffix($suffix)
            ->required()
            // El resumen de las reglas se actualiza al salir de cada casilla.
            ->live(onBlur: true);
    }

    private static function minutes(string $key, string $label, int $min, int $max): TextInput
    {
        return self::number($key, $label, $min, $max, 'min');
    }
}
