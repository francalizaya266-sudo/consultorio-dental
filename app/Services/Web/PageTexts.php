<?php

namespace App\Services\Web;

use App\Models\Setting;

/**
 * El encabezado y la franja de cierre de cada página de la web, como se editan en Página web › Textos de las páginas.
 * Los textos con que viene el sistema están en config/consultorio.php (web.pages).
 */
final class PageTexts
{
    /** La página cuyo cierre usan el inicio y las páginas sin cierre propio. */
    public const GENERAL = 'inicio';

    /** @var array<string, string> Cada página con encabezado propio y su nombre en el panel. */
    public const PAGES = [
        'servicios' => 'Servicios',
        'equipo' => 'Equipo',
        'contacto' => 'Contacto',
        'preguntas' => 'Preguntas frecuentes',
        'promociones' => 'Promociones',
        'casos' => 'Antes y después',
        'opiniones' => 'Opiniones',
    ];

    /** @var list<string> Las que cierran con su propia frase; las demás cierran con la general. */
    public const WITH_CLOSING = ['servicios', 'equipo', 'contacto', 'preguntas'];

    /** @var array<string, string> Lo que se edita de un encabezado y cómo se llama en el panel. */
    public const HEADER_FIELDS = [
        'kicker' => 'Frase corta',
        'title' => 'Título',
        'lead' => 'Texto bajo el título',
        'photo' => 'Foto',
    ];

    /** @var array<string, string> Lo que se edita de una franja de cierre. */
    public const CLOSING_FIELDS = [
        'closing_title' => 'Título del cierre',
        'closing_text' => 'Texto del cierre',
    ];

    /** La página que lleva, bajo los doctores, la franja con lo que distingue al equipo. */
    public const WITH_VALUES = 'equipo';

    /** @var list<string> El ícono de cada punto de esa franja (SiteIcon): tantos puntos como íconos. */
    public const VALUE_ICONS = ['award', 'chat-check', 'shield-check', 'heart'];

    /** @var array<string, string> El ícono que acompaña a la frase corta de cada página (SiteIcon). */
    private const ICONS = [
        'servicios' => 'heart',
        'equipo' => 'users',
        'contacto' => 'pin',
        'preguntas' => 'question',
        'promociones' => 'tag',
        'casos' => 'sparkles',
        'opiniones' => 'star',
    ];

    /** @var list<string> Las páginas cuyo cierre invita a escribir por WhatsApp en vez de a reservar. */
    private const CLOSES_WITH_WHATSAPP = ['preguntas'];

    public function __construct(private ClinicInfo $clinic) {}

    public static function key(string $page, string $field): string
    {
        return "web.pages.{$page}.{$field}";
    }

    /**
     * Todos los ajustes de esta hoja, con el nombre con que salen en el historial de cambios.
     *
     * @return array<string, string>
     */
    public static function settings(): array
    {
        $settings = [];

        foreach (self::CLOSING_FIELDS as $field => $label) {
            $settings[self::key(self::GENERAL, $field)] = $label.' general de la web';
        }

        foreach (self::PAGES as $page => $name) {
            $fields = in_array($page, self::WITH_CLOSING, true) ? [...self::HEADER_FIELDS, ...self::CLOSING_FIELDS] : self::HEADER_FIELDS;

            foreach ($fields as $field => $label) {
                $settings[self::key($page, $field)] = "{$label} de la página {$name}";
            }
        }

        foreach (array_keys(self::VALUE_ICONS) as $index) {
            $number = $index + 1;
            $name = self::PAGES[self::WITH_VALUES];
            $settings[self::valueKey($number, 'title')] = "Punto {$number} de la página {$name}";
            $settings[self::valueKey($number, 'text')] = "Texto del punto {$number} de la página {$name}";
        }

        return $settings;
    }

    /** El ajuste de un punto de la franja de Equipo: su título ("title") o su texto ("text"). Los puntos se cuentan desde 1. */
    public static function valueKey(int $number, string $part): string
    {
        return self::key(self::WITH_VALUES, "value{$number}_{$part}");
    }

    /** Si el cierre de la página invita a escribir por WhatsApp en vez de a reservar. */
    public static function closesWithWhatsApp(string $page): bool
    {
        return in_array($page, self::CLOSES_WITH_WHATSAPP, true);
    }

    public function icon(string $page): string
    {
        return self::ICONS[$page];
    }

    /** La frase corta de arriba del título, o null si la dejaron vacía. */
    public function kicker(string $page): ?string
    {
        return $this->text($page, 'kicker');
    }

    /** El título tal como se escribió en el panel; vacío, vuelve el que trae el sistema. */
    public function title(string $page): string
    {
        return $this->text($page, 'title') ?? (string) config('consultorio.defaults.'.self::key($page, 'title'));
    }

    /**
     * El título en sus tres partes: lo que va antes, lo que va en azul y lo que va después. En azul sale lo que
     * se encerró entre asteriscos ("Contácta*nos*"); sin asteriscos, la última palabra si hay más de una.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function titleParts(string $page): array
    {
        $title = $this->title($page);

        if (preg_match('/^(.*?)\*(.+?)\*(.*)$/us', $title, $parts)) {
            return [$parts[1], $parts[2], $parts[3]];
        }

        $lastSpace = mb_strrpos($title, ' ');

        return $lastSpace === false ? [$title, '', ''] : [mb_substr($title, 0, $lastSpace + 1), mb_substr($title, $lastSpace + 1), ''];
    }

    /** El título sin los asteriscos que marcan la parte en azul: para la pestaña del navegador y para Google. */
    public function plainTitle(string $page): string
    {
        return implode('', $this->titleParts($page));
    }

    public function lead(string $page): ?string
    {
        return $this->text($page, 'lead');
    }

    /** La foto del encabezado: la de la página o, si no subieron una, la del inicio. Null si no hay ninguna. */
    public function photoUrl(string $page): ?string
    {
        return PublicImages::url($this->text($page, 'photo')) ?? $this->clinic->heroImageUrl();
    }

    /**
     * La franja que cierra una página, sobre el pie: su título, su texto y a dónde lleva su botón. Una página sin
     * cierre propio (o ninguna) cierra con la frase general; también la que invita a escribir por WhatsApp, si el
     * consultorio no tiene WhatsApp.
     *
     * @return array{title: string, text: string|null, whatsapp: bool}
     */
    public function closing(?string $page): array
    {
        $whatsapp = $page !== null && self::closesWithWhatsApp($page);
        $own = in_array($page, self::WITH_CLOSING, true) && (! $whatsapp || $this->clinic->whatsappUrl() !== null) ? $page : self::GENERAL;

        return [
            'title' => $this->text($own, 'closing_title') ?? (string) config('consultorio.defaults.'.self::key($own, 'closing_title')),
            'text' => $this->text($own, 'closing_text'),
            'whatsapp' => $whatsapp && $own === $page,
        ];
    }

    /**
     * Los puntos de la franja de Equipo que tienen título, cada uno con su ícono y su texto.
     *
     * @return list<array{icon: string, title: string, text: string|null}>
     */
    public function teamValues(): array
    {
        $values = [];

        foreach (self::VALUE_ICONS as $index => $icon) {
            $number = $index + 1;
            $title = $this->text(self::WITH_VALUES, "value{$number}_title");

            if ($title !== null) {
                $values[] = ['icon' => $icon, 'title' => $title, 'text' => $this->text(self::WITH_VALUES, "value{$number}_text")];
            }
        }

        return $values;
    }

    private function text(string $page, string $field): ?string
    {
        $value = trim((string) Setting::get(self::key($page, $field)));

        return $value === '' ? null : $value;
    }
}
