<?php

namespace App\Support;

use App\Enums\Dentition;

/**
 * Piezas dentarias con numeración FDI y sus superficies, en el orden en que se
 * dibujan en el odontograma (de la derecha del paciente a su izquierda).
 */
final class Teeth
{
    /** Filas del odontograma según la dentición: de arriba hacia abajo. */
    public static function rows(Dentition $dentition): array
    {
        $permanentUpper = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
        $permanentLower = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
        $primaryUpper = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65];
        $primaryLower = [85, 84, 83, 82, 81, 71, 72, 73, 74, 75];

        return match ($dentition) {
            Dentition::Permanent => [$permanentUpper, $permanentLower],
            Dentition::Primary => [$primaryUpper, $primaryLower],
            Dentition::Mixed => [$permanentUpper, $primaryUpper, $primaryLower, $permanentLower],
        };
    }

    /** @return list<int> */
    public static function all(Dentition $dentition): array
    {
        return array_merge(...self::rows($dentition));
    }

    public static function exists(int $tooth, Dentition $dentition): bool
    {
        return in_array($tooth, self::all($dentition), true);
    }

    public static function quadrant(int $tooth): int
    {
        return intdiv($tooth, 10);
    }

    public static function isUpper(int $tooth): bool
    {
        return in_array(self::quadrant($tooth), [1, 2, 5, 6], true);
    }

    /** Incisivos y caninos tienen borde incisal; molares y premolares, cara oclusal. */
    public static function isAnterior(int $tooth): bool
    {
        return $tooth % 10 <= 3;
    }

    /** En el dibujo, la mitad derecha del paciente (cuadrantes 1, 4, 5 y 8) se ve a la izquierda. */
    public static function isPatientRight(int $tooth): bool
    {
        return in_array(self::quadrant($tooth), [1, 4, 5, 8], true);
    }

    /**
     * Superficies de la pieza: V vestibular, P palatina (arriba) o L lingual (abajo),
     * M mesial, D distal, O oclusal (posteriores) o I incisal (anteriores).
     *
     * @return list<string>
     */
    public static function surfaces(int $tooth): array
    {
        return ['V', self::isUpper($tooth) ? 'P' : 'L', 'M', 'D', self::isAnterior($tooth) ? 'I' : 'O'];
    }

    public static function surfaceName(string $surface): string
    {
        return match ($surface) {
            'V' => 'vestibular',
            'P' => 'palatina',
            'L' => 'lingual',
            'M' => 'mesial',
            'D' => 'distal',
            'O' => 'oclusal',
            'I' => 'incisal',
            default => $surface,
        };
    }

    /**
     * Superficie que ocupa cada zona del dibujo (arriba, abajo, izquierda, derecha, centro).
     *
     * @return array{top: string, bottom: string, left: string, right: string, center: string}
     */
    public static function layout(int $tooth): array
    {
        [$vestibular, $inner, , , $center] = self::surfaces($tooth);
        $mesialOnRight = self::isPatientRight($tooth);

        return [
            'top' => self::isUpper($tooth) ? $vestibular : $inner,
            'bottom' => self::isUpper($tooth) ? $inner : $vestibular,
            'left' => $mesialOnRight ? 'D' : 'M',
            'right' => $mesialOnRight ? 'M' : 'D',
            'center' => $center,
        ];
    }
}
