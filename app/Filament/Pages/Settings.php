<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HasSettingsMenuGroup;
use App\Filament\Pages\Concerns\EditsSettings;
use App\Models\Setting;
use App\Support\Phone;
use BackedEnum;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Datos del consultorio, reglas de la agenda y de las reservas web, y lo de dinero (presupuestos, meta y reportes).
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
            'agenda.buffer_minutes' => 'int',
            'agenda.slot_step_minutes' => 'int',
            'booking.min_lead_minutes' => 'int',
            'booking.horizon_days' => 'int',
            'booking.max_web_appointments_per_patient' => 'int',
            'booking.max_web_appointments_per_phone' => 'int',
            'booking.self_reschedule_min_hours' => 'int',
            'booking.self_cancel_min_hours' => 'int',
            'booking.max_reschedule_options' => 'int',
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
                                    ]),
                            ]),
                        Tab::make('Agenda y reservas')
                            ->icon(Heroicon::OutlinedCalendarDays)
                            ->schema([
                                Section::make('Agenda')
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
                                    ]),
                                Section::make('Reservas y cambios desde la web')
                                    ->columns(2)
                                    ->schema([
                                        // Cada casilla vale sola, pero juntas pueden dejar la web sin horarios o sin forma de
                                        // cambiar una cita: se comparan con los días que se puede reservar hacia adelante.
                                        Select::make('booking.min_lead_minutes')
                                            ->label('Anticipación mínima para reservar')
                                            // Un valor guardado antes que no está en la lista (90 minutos, por ejemplo) se conserva.
                                            ->options(fn () => self::leadTimes())
                                            ->selectablePlaceholder(false)
                                            ->required()
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

    private static function number(string $key, string $label, int $min, int $max, string $suffix): TextInput
    {
        return TextInput::make($key)
            ->label($label)
            ->numeric()
            ->integer()
            ->minValue($min)
            ->maxValue($max)
            ->suffix($suffix)
            ->required();
    }

    private static function minutes(string $key, string $label, int $min, int $max): TextInput
    {
        return self::number($key, $label, $min, $max, 'min');
    }
}
