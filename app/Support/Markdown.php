<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

/** Textos que el consultorio escribe en el panel, convertidos para la web sin permitir HTML propio. */
final class Markdown
{
    public static function toHtml(?string $text): HtmlString
    {
        return new HtmlString(filled($text) ? Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]) : '');
    }

    /** Texto simple: cada línea en blanco separa un párrafo. */
    public static function paragraphs(?string $text): HtmlString
    {
        $paragraphs = preg_split('/\R\s*\R/', trim((string) $text)) ?: [];

        return new HtmlString(collect($paragraphs)
            ->filter(fn (string $p) => trim($p) !== '')
            ->map(fn (string $p) => '<p>'.nl2br(e(trim($p)), false).'</p>')
            ->join("\n"));
    }
}
