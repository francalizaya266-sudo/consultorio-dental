<?php

namespace App\Support;

use Illuminate\Support\HtmlString;

/**
 * Íconos de la web (portada, acceso rápido, encabezado y pie): trazos de 24 × 24 que toman el color del texto.
 * Los de las redes y el de WhatsApp van rellenos, como sus logos. Son adornos junto a un texto: sin nombre propio.
 */
final class SiteIcon
{
    /** @var array<string, string> Trazos de cada ícono de línea. */
    private const STROKED = [
        'home' => '<path d="M4 10.8 12 4l8 6.8V19a1.5 1.5 0 0 1-1.5 1.5H15V15H9v5.5H5.5A1.5 1.5 0 0 1 4 19v-8.2Z"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4M16 3v4M3.5 10h17M8 14h.01M12 14h.01M16 14h.01M8 17.3h.01M12 17.3h.01"/>',
        'calendar-check' => '<rect x="3.5" y="5" width="17" height="15.5" rx="3"/><path d="M8 3v4M16 3v4M3.5 10h17M9 15l2.2 2.2 4-4.2"/>',
        'chat-check' => '<path d="M20 11.5a8 8 0 0 1-11.6 7.1L4 20l1.4-4.2A8 8 0 1 1 20 11.5Z"/><path d="m9 11.7 2.1 2.1 4-4.2"/>',
        'wallet' => '<path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H17a1 1 0 0 1 1 1v2M4 7.5V17a2.5 2.5 0 0 0 2.5 2.5H19a1 1 0 0 0 1-1V9a1 1 0 0 0-1-1H6.5A2.5 2.5 0 0 1 4 7.5Z"/><path d="M16.2 13.7h.01"/>',
        'tag' => '<path d="M3.5 12.3V5A1.5 1.5 0 0 1 5 3.5h7.3a2 2 0 0 1 1.4.6l6.2 6.2a2 2 0 0 1 0 2.8l-6.8 6.8a2 2 0 0 1-2.8 0l-6.2-6.2a2 2 0 0 1-.6-1.4Z"/><path d="M8.2 8.2h.01"/>',
        'pin' => '<path d="M12 21.5s7-6.4 7-11.8a7 7 0 0 0-14 0c0 5.4 7 11.8 7 11.8Z"/><circle cx="12" cy="9.7" r="2.6"/>',
        'heart' => '<path d="M12 20s-7.5-4.6-7.5-10.2a4.2 4.2 0 0 1 7.5-2.6 4.2 4.2 0 0 1 7.5 2.6C19.5 15.4 12 20 12 20Z"/>',
        'users' => '<circle cx="9" cy="8.5" r="3.2"/><path d="M3 19.5c.6-3.3 3-5 6-5s5.4 1.7 6 5M15.8 5.6a3.2 3.2 0 0 1 0 5.8M17.4 14.8c2 .6 3.2 2.2 3.6 4.7"/>',
        'question' => '<circle cx="12" cy="12" r="8.5"/><path d="M9.6 9.7a2.5 2.5 0 1 1 3.7 2.2c-.8.5-1.3 1-1.3 2M12 16.9h.01"/>',
        'sparkles' => '<path d="m11 4 1.7 4.8L17.5 10.5l-4.8 1.7L11 17l-1.7-4.8L4.5 10.5l4.8-1.7L11 4ZM18.3 15l.7 1.9 1.9.7-1.9.7-.7 1.9-.7-1.9-1.9-.7 1.9-.7.7-1.9Z"/>',
        'star' => '<path d="m12 3.8 2.5 5.2 5.7.8-4.1 4 1 5.7L12 16.8l-5.1 2.7 1-5.7-4.1-4 5.7-.8L12 3.8Z"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'phone' => '<path d="M6.6 3.5h2.2a1 1 0 0 1 1 .8l.7 3.2a1 1 0 0 1-.5 1.1l-1.6.9a11 11 0 0 0 6.1 6.1l.9-1.6a1 1 0 0 1 1.1-.5l3.2.7a1 1 0 0 1 .8 1v2.2a2 2 0 0 1-2.2 2A16.5 16.5 0 0 1 4.6 5.7 2 2 0 0 1 6.6 3.5Z"/>',
        'mail' => '<rect x="3.5" y="5.5" width="17" height="13" rx="2.5"/><path d="m4.5 7.5 7.5 5.5 7.5-5.5"/>',
        'award' => '<circle cx="12" cy="9" r="5.5"/><path d="M8.6 13.4 7.5 20.5 12 18l4.5 2.5-1.1-7.1M9.8 9.1l1.6 1.6 2.9-3"/>',
        'shield-check' => '<path d="M12 3.5 5 6v5.5c0 4.3 2.9 7.6 7 9 4.1-1.4 7-4.7 7-9V6l-7-2.5Z"/><path d="m9 11.8 2.2 2.2 3.9-4.1"/>',
        'user' => '<circle cx="12" cy="8.5" r="3.7"/><path d="M5 19.5c.8-3.2 3.6-5 7-5s6.2 1.8 7 5"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'arrow-right' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'arrow-down' => '<path d="M12 5v14M6 13l6 6 6-6"/>',
        'instagram' => '<rect x="3.5" y="3.5" width="17" height="17" rx="5"/><circle cx="12" cy="12" r="4"/><path d="M17.3 6.7h.01"/>',
    ];

    /** @var array<string, array{0: string, 1: string}> Íconos rellenos: su recuadro y su trazo. */
    private const FILLED = [
        'whatsapp' => ['0 0 32 32', '<path d="M16.02 3C8.84 3 3 8.83 3 16c0 2.29.6 4.53 1.74 6.5L3 29l6.68-1.75A12.96 12.96 0 0 0 16.02 29C23.2 29 29 23.17 29 16S23.2 3 16.02 3Zm0 23.8c-2.02 0-4-.54-5.72-1.57l-.41-.24-3.96 1.04 1.06-3.86-.27-.4A10.73 10.73 0 0 1 5.2 16c0-5.96 4.85-10.8 10.82-10.8 5.96 0 10.8 4.84 10.8 10.8 0 5.96-4.84 10.8-10.8 10.8Zm5.93-8.09c-.33-.16-1.93-.95-2.23-1.06-.3-.11-.52-.16-.73.17-.22.32-.84 1.05-1.03 1.27-.19.22-.38.24-.71.08-.33-.16-1.38-.51-2.63-1.62-.97-.87-1.63-1.94-1.82-2.27-.19-.32-.02-.5.14-.66.15-.15.33-.38.49-.57.16-.19.22-.32.33-.54.11-.22.05-.4-.03-.57-.08-.16-.73-1.76-1-2.41-.26-.63-.53-.55-.73-.56h-.62c-.22 0-.57.08-.87.4-.3.33-1.14 1.11-1.14 2.71s1.17 3.15 1.33 3.36c.16.22 2.3 3.5 5.56 4.91.78.34 1.38.54 1.86.69.78.25 1.49.21 2.05.13.63-.09 1.93-.79 2.2-1.55.27-.76.27-1.41.19-1.55-.08-.14-.3-.22-.62-.38Z"/>'],
        'facebook' => ['0 0 24 24', '<path d="M13.6 21v-8h2.7l.4-3.2h-3.1V7.8c0-.9.3-1.5 1.6-1.5h1.7V3.4c-.3 0-1.3-.1-2.4-.1-2.4 0-4.1 1.5-4.1 4.2v2.3H7.7V13h2.7v8h3.2Z"/>'],
        'tiktok' => ['0 0 24 24', '<path d="M16.5 3c.3 2.1 1.5 3.6 3.6 3.9v3c-1.3 0-2.5-.4-3.6-1.1v5.9c0 3.4-2.4 5.9-5.6 5.9a5.4 5.4 0 0 1-5.5-5.5c0-3.3 2.8-5.8 6.2-5.4v3.1c-1.6-.4-3.1.6-3.1 2.2 0 1.4 1.1 2.4 2.4 2.4 1.5 0 2.5-1.1 2.5-2.8V3h3.1Z"/>'],
    ];

    public static function svg(string $name): HtmlString
    {
        if (isset(self::FILLED[$name])) {
            [$box, $path] = self::FILLED[$name];

            return new HtmlString('<svg viewBox="'.$box.'" fill="currentColor" aria-hidden="true">'.$path.'</svg>');
        }

        $paths = self::STROKED[$name] ?? self::STROKED['arrow-right'];

        return new HtmlString('<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.$paths.'</svg>');
    }
}
