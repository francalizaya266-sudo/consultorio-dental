<?php

namespace App\Filament\Pages;

use App\Filament\Forms\WebPhotoUpload;
use App\Filament\Pages\Concerns\EditsSettings;
use App\Services\Web\PublicImages;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
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

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Inicio')
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
                    ->description('Las estrellas salen en el inicio, con enlace a las opiniones.')
                    ->columns(2)
                    ->schema([
                        TextInput::make('clinic.reviews_url')
                            ->label('Enlace a sus opiniones en Google')
                            ->url()
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
                    ->columns(2)
                    ->schema([
                        TextInput::make('clinic.maps_url')
                            ->label('Enlace de Google Maps')
                            ->url()
                            ->helperText('Para el botón "Cómo llegar". Vacío = se busca la dirección.'),
                        TextInput::make('clinic.hours_note')
                            ->label('Nota sobre el horario')
                            ->placeholder('Feriados: con cita previa'),
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
