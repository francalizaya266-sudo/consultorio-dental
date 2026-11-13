<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\EditsSettings;
use App\Support\Phone;
use BackedEnum;
use Closure;
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
 * Datos del consultorio y reglas de la agenda y de las reservas web. Lo de la página web va en
 * Página web › Puesta a punto. Lo que se guarda aquí reemplaza los valores de config/consultorio.php.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    use EditsSettings;

    /** Cada cuántos minutos se pueden ofrecer horarios: valores que caen en horas redondas. */
    public const SLOT_STEPS = [5, 10, 15, 20, 30, 60];

    protected static ?string $title = 'Ajustes';

    protected static ?string $slug = 'ajustes';

    protected static ?string $navigationParentItem = 'Configuración';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

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
                                Section::make('Presupuestos')
                                    ->columns(2)
                                    ->schema([
                                        self::number('plans.valid_days', 'Un presupuesto vale por', 7, 365, 'días')
                                            ->helperText('Desde que se presenta. Sale en la hoja y en el WhatsApp: "vale hasta el …". Lo aceptado mantiene su precio.'),
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
                                        self::minutes('booking.min_lead_minutes', 'Anticipación mínima para reservar', 0, 2880)
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
                                            ->rule(fn (Get $get) => self::lessThanTheHorizon($get, 1, 'Así ningún paciente puede cambiar su cita solo: son más horas que las que se puede reservar hacia adelante.')),
                                        self::number('booking.self_cancel_min_hours', 'El paciente puede cancelar solo hasta', 0, 168, 'horas antes')
                                            ->rule(fn (Get $get) => self::lessThanTheHorizon($get, 1, 'Así ningún paciente puede cancelar solo: son más horas que las que se puede reservar hacia adelante.')),
                                        self::number('booking.max_reschedule_options', 'Horarios que puede proponer el paciente', 1, 5, 'opciones'),
                                    ]),
                            ]),
                        Tab::make('Reportes')
                            ->icon(Heroicon::OutlinedChartBar)
                            ->schema([
                                Section::make('Reportes')
                                    ->schema([
                                        Toggle::make('reports.doctors_see_own')
                                            ->label('Cada doctor ve sus propios ingresos y gráficos')
                                            ->helperText('Solo lo suyo: lo que atendió y lo que se cobró de sus citas. Recepción no ve reportes.'),
                                        TextInput::make('reports.monthly_goal')
                                            ->label('Meta de cobro del mes')
                                            ->prefix('S/')
                                            ->numeric()
                                            ->integer()
                                            ->minValue(1)
                                            ->maxValue(10_000_000)
                                            ->helperText('Opcional. En "Hoy", la administración ve cuánto falta y si el mes va a buen ritmo.'),
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
