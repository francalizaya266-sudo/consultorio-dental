<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Concerns\EditsSettings;
use App\Support\Phone;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
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
                                                if (! Phone::isValid((string) $value)) {
                                                    $fail('Escribe un celular válido.');
                                                }
                                            }),
                                        TextInput::make('clinic.phone')
                                            ->label('Teléfono fijo'),
                                        TextInput::make('clinic.address')
                                            ->label('Dirección')
                                            ->helperText('Sale en los avisos como {direccion}, en la web y en el mapa.'),
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
                                        self::minutes('agenda.slot_step_minutes', 'Cada cuánto se ofrecen horarios', 5, 60)
                                            ->helperText('Cuando no hay citas cerca. Los horarios pegados a otras citas se ofrecen primero.'),
                                    ]),
                                Section::make('Reservas y cambios desde la web')
                                    ->columns(2)
                                    ->schema([
                                        self::minutes('booking.min_lead_minutes', 'Anticipación mínima para reservar', 0, 2880),
                                        self::number('booking.horizon_days', 'Hasta cuántos días adelante se puede reservar', 1, 180, 'días'),
                                        self::number('booking.max_web_appointments_per_patient', 'Citas web futuras por paciente', 1, 10, 'citas'),
                                        self::number('booking.max_web_appointments_per_phone', 'Citas web futuras por celular (toda la familia)', 1, 30, 'citas'),
                                        self::number('booking.self_reschedule_min_hours', 'Cambio automático si faltan más de', 1, 168, 'horas')
                                            ->helperText('Con menos tiempo, el paciente propone horarios y ustedes aprueban.'),
                                        self::number('booking.self_cancel_min_hours', 'El paciente puede cancelar solo hasta', 0, 168, 'horas antes'),
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
