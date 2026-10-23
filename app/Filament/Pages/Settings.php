<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Models\User;
use App\Support\Phone;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Arr;
use UnitEnum;

/**
 * Datos del consultorio y reglas de la agenda y de las reservas web.
 * Lo que se guarda aquí reemplaza los valores de config/consultorio.php.
 *
 * @property-read Schema $form
 */
class Settings extends Page
{
    /** Ajustes que se pueden cambiar desde el panel y su tipo. */
    private const KEYS = [
        'clinic.name' => 'string',
        'clinic.whatsapp' => 'phone',
        'clinic.phone' => 'string',
        'clinic.address' => 'string',
        'clinic.district' => 'string',
        'clinic.email' => 'string',
        'clinic.maps_url' => 'string',
        'clinic.reviews_url' => 'string',
        'clinic.instagram' => 'string',
        'clinic.facebook' => 'string',
        'clinic.tiktok' => 'string',
        'clinic.hours_note' => 'string',
        'web.headline' => 'string',
        'web.intro' => 'string',
        'web.about' => 'string',
        'agenda.buffer_minutes' => 'int',
        'agenda.slot_step_minutes' => 'int',
        'booking.min_lead_minutes' => 'int',
        'booking.horizon_days' => 'int',
        'booking.max_web_appointments_per_patient' => 'int',
        'booking.max_web_appointments_per_phone' => 'int',
        'booking.self_reschedule_min_hours' => 'int',
        'booking.self_cancel_min_hours' => 'int',
        'booking.max_reschedule_options' => 'int',
    ];

    protected static ?string $title = 'Ajustes';

    protected static ?string $slug = 'ajustes';

    protected static string|UnitEnum|null $navigationGroup = 'Configuración';

    protected static ?int $navigationSort = 10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        /** @var User|null $user */
        $user = auth()->user();

        return (bool) $user?->isAdmin();
    }

    public function mount(): void
    {
        $state = [];

        foreach (array_keys(self::KEYS) as $key) {
            Arr::set($state, $key, Setting::get($key));
        }

        $this->form->fill($state);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
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
                            ->label('Distrito')
                            ->placeholder('Miraflores')
                            ->helperText('Para aparecer en Google como "dentista en …".'),
                        TextInput::make('clinic.email')
                            ->label('Correo')
                            ->email(),
                    ]),
                Section::make('Web')
                    ->description('Textos del inicio y enlaces del consultorio. El horario de atención sale solo de los horarios de los doctores.')
                    ->columns(2)
                    ->collapsed()
                    ->schema([
                        TextInput::make('web.headline')
                            ->label('Título del inicio')
                            ->required()
                            ->maxLength(60)
                            ->helperText('Corto: que entre en dos líneas en el celular.'),
                        Textarea::make('web.intro')
                            ->label('Frase bajo el título')
                            ->required()
                            ->rows(2)
                            ->maxLength(220),
                        Textarea::make('web.about')
                            ->label('Sobre el consultorio')
                            ->rows(4)
                            ->maxLength(1200)
                            ->helperText('Quiénes son y cómo atienden. Sale en el inicio.')
                            ->columnSpanFull(),
                        TextInput::make('clinic.maps_url')
                            ->label('Enlace de Google Maps')
                            ->url()
                            ->helperText('Para el botón "Cómo llegar". Vacío = se busca la dirección.'),
                        TextInput::make('clinic.reviews_url')
                            ->label('Enlace a sus opiniones en Google')
                            ->url(),
                        TextInput::make('clinic.instagram')
                            ->label('Instagram')
                            ->url()
                            ->placeholder('https://instagram.com/…'),
                        TextInput::make('clinic.facebook')
                            ->label('Facebook')
                            ->url()
                            ->placeholder('https://facebook.com/…'),
                        TextInput::make('clinic.tiktok')
                            ->label('TikTok')
                            ->url()
                            ->placeholder('https://tiktok.com/@…'),
                        TextInput::make('clinic.hours_note')
                            ->label('Nota sobre el horario')
                            ->placeholder('Feriados: con cita previa'),
                    ]),
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
            ])
            ->statePath('data');
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                Form::make([EmbeddedSchema::make('form')])
                    ->id('form')
                    ->livewireSubmitHandler('save')
                    ->footer([
                        Actions::make([
                            Action::make('save')->label('Guardar')->submit('save'),
                        ]),
                    ]),
            ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach (self::KEYS as $key => $type) {
            $value = Arr::get($state, $key);

            Setting::set($key, match ($type) {
                'int' => (int) $value,
                'phone' => Phone::normalize((string) $value),
                default => filled($value) ? (string) $value : null,
            });
        }

        Notification::make()->success()->title('Ajustes guardados')->send();
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
