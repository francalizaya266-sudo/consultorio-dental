<?php

namespace App\Filament\Pages;

use App\Filament\Forms\WebPhotoUpload;
use App\Filament\Pages\Concerns\EditsSettings;
use App\Services\Web\OpeningHours;
use App\Services\Web\PublicImages;
use App\Services\Web\SetupChecklist;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Lo que la página web dice del consultorio: el inicio, el logo, las estrellas de Google y los enlaces.
 * Va en el grupo Página web, junto a campañas, casos y opiniones: la web se edita desde un solo lugar.
 *
 * @property-read Schema $form
 */
class WebSetup extends Page
{
    use EditsSettings;

    protected static ?string $title = 'Puesta a punto';

    protected static ?string $slug = 'pagina-web';

    protected static ?string $navigationParentItem = 'Página web';

    protected static ?int $navigationSort = 0;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    /** Cuántas cosas faltan para que la web dé confianza: se ve en el menú sin tener que entrar. */
    public static function getNavigationBadge(): ?string
    {
        $missing = count(app(SetupChecklist::class)->missing());

        return $missing > 0 ? (string) $missing : null;
    }

    public static function getNavigationBadgeColor(): string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'Lo que falta configurar para la web';
    }

    public function getSubheading(): string
    {
        return 'Lo que la página dice del consultorio. El horario de atención sale solo de los horarios de los doctores.';
    }

    protected static function settingKeys(): array
    {
        return [
            'web.headline' => 'string',
            'web.intro' => 'string',
            'web.about' => 'string',
            'web.hero_image' => 'string',
            'web.logo' => 'string',
            'clinic.maps_url' => 'string',
            'clinic.reviews_url' => 'string',
            'clinic.google_rating' => 'rating',
            'clinic.google_reviews_count' => 'optional_int',
            'clinic.instagram' => 'string',
            'clinic.facebook' => 'string',
            'clinic.tiktok' => 'string',
            'clinic.hours_note' => 'string',
        ];
    }

    protected function savedMessage(): string
    {
        return 'Página web guardada';
    }

    /** El horario que la web ya muestra, en una línea, para que la nota solo agregue lo que falta. */
    private static function hoursOnTheWeb(): string
    {
        $hours = OpeningHours::fromSchedules();

        if ($hours->isEmpty()) {
            return 'La web todavía no muestra ningún horario: ponlo en la ficha de cada doctor. Esta nota va debajo.';
        }

        $lines = collect($hours->lines())
            ->reject(fn (array $line) => $line['hours'] === ['Cerrado'])
            ->map(fn (array $line) => $line['days'].' '.implode(' y ', $line['hours']))
            ->implode('; ');

        return "La web ya muestra el horario de los doctores ({$lines}). Esta nota va debajo, para lo que el horario no dice.";
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Lo que falta para que la web dé confianza, cada cosa con su enlace. Desaparece cuando está todo.
                View::make('filament.pages.setup-checklist')
                    ->viewData(fn () => ['items' => app(SetupChecklist::class)->items()])
                    ->visible(fn () => app(SetupChecklist::class)->missing() !== []),
                Section::make('Inicio')
                    ->id('inicio')
                    ->columns(2)
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
                        WebPhotoUpload::make('web.hero_image', 'inicio')
                            ->label('Foto del inicio')
                            ->imageEditor()
                            ->helperText('Una foto real del consultorio o del equipo, horizontal. Sale arriba de todo en el inicio.')
                            ->columnSpanFull(),
                        // El logo se guarda tal cual (PNG con fondo transparente, JPG o WebP): no se pasa a JPG como las fotos.
                        FileUpload::make('web.logo')
                            ->label('Logo')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->disk(PublicImages::DISK)
                            ->directory('marca')
                            ->visibility('public')
                            ->maxSize(2 * 1024)
                            ->helperText('Sale arriba en la página web y en este panel, en lugar del nombre. Mejor en PNG con fondo transparente. Si lo quitas, vuelve el nombre.')
                            ->columnSpanFull(),
                    ]),
                Section::make('Opiniones en Google')
                    ->id('opiniones')
                    ->description('Las estrellas salen en el inicio, con enlace a las opiniones.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('clinic.reviews_url')
                            ->label('Enlace a sus opiniones en Google')
                            ->url()
                            ->helperText('También va en el WhatsApp con que recepción pide su opinión a los pacientes que vienen seguido y están al día. Sin enlace, no se pide.')
                            ->columnSpanFull(),
                        TextInput::make('clinic.google_rating')
                            ->label('Puntaje en Google')
                            ->placeholder('4,9')
                            ->rule(fn () => function (string $attribute, $value, $fail) {
                                if (filled($value) && self::rating($value) === null) {
                                    $fail('Escribe un puntaje de 1 a 5, por ejemplo 4,9.');
                                }
                            })
                            ->helperText('Cópialo tal como sale en Google. Vacío = no se muestran estrellas.'),
                        TextInput::make('clinic.google_reviews_count')
                            ->label('Cantidad de opiniones en Google')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->suffix('opiniones'),
                    ]),
                Section::make('Cómo llegar y redes')
                    ->id('como-llegar')
                    // El nombre, la dirección y los teléfonos que la web muestra se cambian en Ajustes: se llega con un enlace.
                    ->description('La dirección y los teléfonos que salen en la web son los de Configuración › Ajustes.')
                    ->headerActions([
                        Action::make('clinicData')
                            ->label('Nombre, dirección y teléfonos: en Ajustes')
                            ->link()
                            ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                            ->url(fn () => Settings::getUrl()),
                    ])
                    ->columns(2)
                    ->schema([
                        TextInput::make('clinic.maps_url')
                            ->label('Enlace de Google Maps')
                            ->url()
                            ->helperText('Para el botón "Cómo llegar". Vacío = se busca la dirección.'),
                        // El horario de la web sale de los horarios de los doctores: se muestra aquí para que la nota
                        // no lo repita a mano, solo diga lo que el horario no dice.
                        TextInput::make('clinic.hours_note')
                            ->label('Nota sobre el horario')
                            ->placeholder('Feriados: con cita previa')
                            ->helperText(fn () => self::hoursOnTheWeb()),
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
                    ]),
            ])
            ->statePath('data');
    }
}
