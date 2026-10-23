<?php

namespace App\Support;

/**
 * Qué significa cada sigla del odontograma (norma técnica del MINSA), por hallazgo: una palabra
 * que se lee en el botón, también en la tablet, y el nombre completo para la ayuda y la impresión.
 */
final class OdontogramAbbreviations
{
    /** @var array<string, array<string, array{0: string, 1: string}>> */
    public const MEANINGS = [
        'caries' => [
            'MB' => ['mancha blanca', 'Lesión de mancha blanca'],
            'CE' => ['esmalte', 'Caries del esmalte'],
            'CD' => ['dentina', 'Caries de la dentina'],
            'CDP' => ['con pulpa', 'Caries de la dentina con compromiso de la pulpa'],
        ],
        'restauracion' => [
            'AM' => ['amalgama', 'Amalgama dental'],
            'R' => ['resina', 'Resina'],
            'IV' => ['ionómero', 'Ionómero de vidrio'],
            'IM' => ['incrust. metálica', 'Incrustación metálica'],
            'IE' => ['incrust. estética', 'Incrustación estética'],
            'C' => ['carilla', 'Carilla estética'],
        ],
        'sellante' => ['S' => ['sellante', 'Sellante']],
        'defecto_esmalte' => [
            'HP' => ['hipoplasia', 'Hipoplasia del esmalte'],
            'HM' => ['hipomineralización', 'Hipomineralización'],
            'O' => ['opacidad', 'Opacidad del esmalte'],
            'D' => ['decoloración', 'Decoloración del esmalte'],
            'Fluorosis' => ['fluorosis', 'Fluorosis'],
        ],
        'superficie_desgastada' => ['DES' => ['desgaste', 'Superficie desgastada']],
        'fosas_fisuras' => ['FFP' => ['fosas profundas', 'Fosas y fisuras profundas']],
        'tratamiento_pulpar' => [
            'TC' => ['conductos', 'Tratamiento de conductos'],
            'PC' => ['pulpectomía', 'Pulpectomía'],
            'PP' => ['pulpotomía', 'Pulpotomía'],
        ],
        'corona' => [
            'CM' => ['metálica', 'Corona metálica'],
            'CF' => ['fenestrada', 'Corona fenestrada'],
            'CMC' => ['metal-cerámica', 'Corona metal-cerámica'],
            'CV' => ['veneer', 'Corona veneer'],
            'CLM' => ['libre de metal', 'Corona libre de metal'],
        ],
        'corona_temporal' => ['CT' => ['temporal', 'Corona temporal']],
        'implante' => ['IMP' => ['implante', 'Implante dental']],
        'remanente_radicular' => ['RR' => ['resto de raíz', 'Remanente radicular']],
        'posicion' => [
            'M' => ['mesializada', 'Pieza mesializada'],
            'D' => ['distalizada', 'Pieza distalizada'],
            'V' => ['vestibularizada', 'Pieza vestibularizada'],
            'P' => ['palatinizada', 'Pieza palatinizada'],
            'L' => ['lingualizada', 'Pieza lingualizada'],
        ],
        'movilidad' => [
            'M1' => ['grado 1', 'Movilidad de grado 1'],
            'M2' => ['grado 2', 'Movilidad de grado 2'],
            'M3' => ['grado 3', 'Movilidad de grado 3'],
        ],
        'impactacion' => ['I' => ['impactada', 'Pieza impactada']],
        'macrodoncia' => ['MAC' => ['macrodoncia', 'Macrodoncia']],
        'microdoncia' => ['MIC' => ['microdoncia', 'Microdoncia']],
    ];

    /** "dentina" para la CD de una caries; null si la sigla no está en la norma. */
    public static function word(string $condition, ?string $abbreviation): ?string
    {
        return self::MEANINGS[$condition][$abbreviation][0] ?? null;
    }

    /** "Caries de la dentina" para la CD de una caries. */
    public static function full(string $condition, ?string $abbreviation): ?string
    {
        return self::MEANINGS[$condition][$abbreviation][1] ?? null;
    }

    /** "CD · dentina", o solo la sigla si no tiene significado conocido. */
    public static function withWord(string $condition, string $abbreviation): string
    {
        $word = self::word($condition, $abbreviation);

        return $word && mb_strtolower($word) !== mb_strtolower($abbreviation) ? "{$abbreviation} · {$word}" : $abbreviation;
    }
}
