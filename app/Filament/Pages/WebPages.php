<?php

namespace App\Filament\Pages;

use App\Filament\Forms\WebPhotoUpload;
use App\Filament\Pages\Concerns\EditsSettings;
use App\Services\Web\PageTexts;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Con qué abre y con qué cierra cada página de la web: la frase corta, el título, el texto y la foto del encabezado,
 * y la frase de la franja final. Una pestaña por página; lo que trae el sistema está en config/consultorio.php.
 *
 * @property-read Schema $form
 */
class WebPages extends Page
{
    use EditsSettings;

    /** @var array<string, string> La ruta pública de cada página, para verla desde su pestaña. */
    private const ROUTES = [
        'servicios' => 'site.treatments',
        'equipo' => 'site.team',
        'contacto' => 'site.contact',
        'preguntas' => 'site.faq',
        'promociones' => 'site.promotions',
        'casos' => 'site.cases',
        'opiniones' => 'site.reviews',
    ];

    protected static ?string $title = 'Textos de las páginas';

    protected static ?string $slug = 'textos-de-las-paginas';

    protected static ?string $navigationParentItem = 'Página web';

    protected static ?int $navigationSort = 1;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    public function getSubheading(): string
    {
        return 'El encabezado y la franja final de cada página. Los servicios, los doctores, las preguntas y las promociones se cambian en su propia sección.';
    }

    protected static function settingKeys(): array
    {
        return array_fill_keys(array_keys(PageTexts::settings()), 'string');
    }

    protected function savedMessage(): string
    {
        return 'Textos de las páginas guardados';
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];

        foreach (PageTexts::PAGES as $page => $name) {
            $tabs[] = Tab::make($name)->schema([
                $this->headerSection($page),
                ...(in_array($page, PageTexts::WITH_CLOSING, true) ? [$this->closingSection($page)] : []),
            ]);
        }

        $tabs[] = Tab::make('Cierre general')->schema([$this->closingSection(PageTexts::GENERAL)]);

        return $schema
            ->components([
                Tabs::make('Páginas')
                    ->contained(false)
                    ->persistTabInQueryString()
                    ->tabs($tabs),
            ])
            ->statePath('data');
    }

    /** La franja celeste con que abre una página. */
    private function headerSection(string $page): Section
    {
        return Section::make('Encabezado')
            ->description('La franja celeste con que abre la página.'.(in_array($page, PageTexts::WITH_CLOSING, true) ? '' : ' Esta página cierra con la frase de la pestaña Cierre general.'))
            ->headerActions([
                Action::make('see_'.$page)
                    ->label('Ver la página')
                    ->link()
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn () => route(self::ROUTES[$page]), shouldOpenInNewTab: true),
            ])
            ->columns(2)
            ->schema([
                TextInput::make(PageTexts::key($page, 'kicker'))
                    ->label(PageTexts::HEADER_FIELDS['kicker'])
                    ->maxLength(50)
                    ->helperText('Va arriba del título, en una pastilla con su ícono. Vacía = no sale.'),
                TextInput::make(PageTexts::key($page, 'title'))
                    ->label(PageTexts::HEADER_FIELDS['title'])
                    ->required()
                    ->maxLength(40)
                    ->helperText('La última palabra sale en azul. Para elegir otra parte, enciérrala entre asteriscos: Contácta*nos*.'),
                Textarea::make(PageTexts::key($page, 'lead'))
                    ->label(PageTexts::HEADER_FIELDS['lead'])
                    ->rows(2)
                    ->maxLength(220)
                    ->helperText('Una o dos líneas. Vacío = no sale.')
                    ->columnSpanFull(),
                WebPhotoUpload::make(PageTexts::key($page, 'photo'), 'paginas')
                    ->label(PageTexts::HEADER_FIELDS['photo'])
                    ->imageEditor()
                    ->helperText('Una foto real del consultorio o del equipo, horizontal. Sale a la derecha del título y se funde con el celeste. Sin foto, se usa la del inicio.')
                    ->columnSpanFull(),
            ]);
    }

    /** La franja azul del final, sobre el pie: la de una página o la general. */
    private function closingSection(string $page): Section
    {
        $general = $page === PageTexts::GENERAL;

        return Section::make($general ? 'Cierre general' : 'Cierre')
            ->description(match (true) {
                $general => 'La franja azul del final del inicio y de las páginas que no tienen un cierre propio, con el botón "Reservar cita".',
                PageTexts::closesWithWhatsApp($page) => 'La franja azul del final de la página, con el botón para escribir por WhatsApp.',
                default => 'La franja azul del final de la página, con el botón "Reservar cita".',
            })
            ->columns(2)
            ->schema([
                TextInput::make(PageTexts::key($page, 'closing_title'))
                    ->label(PageTexts::CLOSING_FIELDS['closing_title'])
                    ->required()
                    ->maxLength(60),
                TextInput::make(PageTexts::key($page, 'closing_text'))
                    ->label(PageTexts::CLOSING_FIELDS['closing_text'])
                    ->maxLength(120)
                    ->helperText('Vacío = no sale.'),
            ]);
    }
}
