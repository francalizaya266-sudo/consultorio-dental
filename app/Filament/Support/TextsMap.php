<?php

namespace App\Filament\Support;

use App\Filament\Resources\ConsentTemplates\ConsentTemplateResource;
use App\Filament\Resources\MessageTemplates\MessageTemplateResource;
use App\Filament\Resources\TextSnippets\TextSnippetResource;
use App\Filament\Resources\Treatments\TreatmentResource;
use Illuminate\Support\HtmlString;

/**
 * Los textos que el sistema envía, imprime o completa viven en cuatro pantallas. Cada una dice, bajo su título,
 * dónde están las otras tres, con su enlace.
 */
final class TextsMap
{
    /** @var array<string, array{0: string, 1: class-string}> */
    private const PLACES = [
        'messages' => ['Mensajes de WhatsApp', MessageTemplateResource::class],
        'snippets' => ['Opciones rápidas de la historia', TextSnippetResource::class],
        'consents' => ['Textos de consentimiento', ConsentTemplateResource::class],
        'treatments' => ['Indicaciones antes y después de cada tratamiento', TreatmentResource::class],
    ];

    /**
     * La frase de la pantalla y, después, los enlaces a los otros textos que la persona puede abrir.
     *
     * @param  string  $current  La pantalla en la que se está: messages, snippets o consents.
     */
    public static function subheading(string $lead, string $current): HtmlString
    {
        $links = collect(self::PLACES)
            ->except($current)
            ->filter(fn (array $place) => $place[1]::canAccess())
            ->map(fn (array $place) => '<a href="'.e($place[1]::getUrl('index')).'">'.e($place[0]).'</a>');

        return new HtmlString(e($lead).($links->isEmpty() ? '' : ' Otros textos: '.$links->implode(' · ').'.'));
    }
}
