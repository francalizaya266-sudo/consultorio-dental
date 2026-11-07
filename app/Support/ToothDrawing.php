<?php

namespace App\Support;

/**
 * Silueta de cada pieza para el odontograma: corona y raíces según el tipo de diente (incisivo, canino,
 * premolar o molar, permanente o deciduo), vista por vestibular.
 *
 * Las medidas son de una pieza superior con el borde oclusal en y = 0, la corona hacia abajo hasta ahí y las
 * raíces hacia arriba (y negativa). La cara mesial queda a la derecha (x positiva). El dibujo voltea la pieza
 * inferior y refleja la mitad izquierda del paciente, para que mesial mire siempre a la línea media.
 */
final class ToothDrawing
{
    /**
     * Por tipo de pieza y posición (1 = incisivo central … 8 = tercer molar): media corona en su parte más ancha,
     * medio cuello, alto de la corona, largo de la raíz, número de raíces y forma del borde oclusal.
     *
     * @var array<string, array<int, array{0: float, 1: float, 2: float, 3: float, 4: int, 5: string}>>
     */
    private const SIZES = [
        'upper' => [
            1 => [7.5, 5.0, 30, 40, 1, 'incisal'],
            2 => [6.0, 4.2, 27, 38, 1, 'incisal'],
            3 => [7.0, 4.8, 31, 52, 1, 'canine'],
            4 => [7.0, 5.0, 27, 42, 2, 'premolar'],
            5 => [6.8, 5.0, 26, 42, 1, 'premolar'],
            6 => [10.0, 8.0, 25, 40, 3, 'molar'],
            7 => [9.3, 7.5, 24, 38, 3, 'molar'],
            8 => [8.5, 7.0, 22, 32, 3, 'molar'],
        ],
        'lower' => [
            1 => [4.6, 3.3, 26, 38, 1, 'incisal'],
            2 => [5.0, 3.5, 27, 40, 1, 'incisal'],
            3 => [6.5, 4.6, 31, 48, 1, 'canine'],
            4 => [6.8, 4.8, 26, 42, 1, 'premolar'],
            5 => [7.0, 5.0, 26, 42, 1, 'premolar'],
            6 => [10.5, 8.5, 25, 40, 2, 'molar'],
            7 => [10.0, 8.0, 24, 38, 2, 'molar'],
            8 => [9.5, 7.5, 23, 32, 2, 'molar'],
        ],
        'primaryUpper' => [
            1 => [6.0, 4.0, 22, 30, 1, 'incisal'],
            2 => [5.0, 3.6, 21, 29, 1, 'incisal'],
            3 => [6.0, 4.0, 24, 34, 1, 'canine'],
            4 => [7.5, 6.0, 20, 28, 3, 'molar'],
            5 => [9.0, 7.0, 21, 29, 3, 'molar'],
        ],
        'primaryLower' => [
            1 => [4.0, 2.9, 21, 29, 1, 'incisal'],
            2 => [4.3, 3.1, 21, 29, 1, 'incisal'],
            3 => [5.5, 3.8, 23, 32, 1, 'canine'],
            4 => [7.5, 6.0, 20, 28, 2, 'molar'],
            5 => [9.5, 7.5, 21, 29, 2, 'molar'],
        ],
    ];

    /**
     * El dibujo de la pieza, en el marco descrito arriba.
     *
     * @return array{
     *     crown: string,
     *     roots: string,
     *     palatal: string|null,
     *     canals: list<array{0: float, 1: float, 2: float, 3: float}>,
     *     implant: string,
     *     threads: list<array{0: float, 1: float, 2: float, 3: float}>,
     *     halfWidth: float,
     *     crownHeight: float,
     *     height: float,
     * }
     */
    public static function shape(int $tooth): array
    {
        [$halfWidth, $neck, $crownHeight, $rootLength, $roots, $edge] = self::sizes($tooth);

        $cej = -$crownHeight;
        $apex = -($crownHeight + $rootLength);
        $furcation = $cej - $rootLength * 0.3;

        return [
            'crown' => self::crown($halfWidth, $neck, $cej, $edge),
            'roots' => match ($roots) {
                1 => self::singleRoot($neck, $cej, $apex, $rootLength),
                default => self::twoRoots($neck, $cej, $roots === 3 ? $apex + 4 : $apex, $furcation, $rootLength),
            },
            'palatal' => $roots === 3 ? self::palatalRoot($furcation, $apex) : null,
            'canals' => self::canals($roots, $neck, $cej, $roots === 3 ? $apex + 4 : $apex, $furcation, $apex),
            'implant' => self::implant($neck, $cej, $apex),
            'threads' => self::threads($neck, $cej, $apex),
            'halfWidth' => $halfWidth,
            'crownHeight' => (float) $crownHeight,
            'height' => (float) ($crownHeight + $rootLength),
        ];
    }

    /** Lo más alto que llega una pieza de esa fila (para saber cuánto mide la fila). */
    public static function tallest(array $teeth): float
    {
        return (float) max(array_map(fn (int $tooth) => self::shape($tooth)['height'], $teeth ?: [11]));
    }

    /**
     * Medidas de la pieza. Los anchos se acercan entre sí (un incisivo inferior real es la mitad de un molar),
     * para que todas las piezas se lean y se toquen bien en su casilla.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: int, 5: string}
     */
    private static function sizes(int $tooth): array
    {
        $primary = Teeth::quadrant($tooth) >= 5;
        $upper = Teeth::isUpper($tooth);
        $set = match (true) {
            $primary && $upper => 'primaryUpper',
            $primary => 'primaryLower',
            $upper => 'upper',
            default => 'lower',
        };
        $position = max(1, min($tooth % 10, $primary ? 5 : 8));
        [$halfWidth, $neck, $crownHeight, $rootLength, $roots, $edge] = self::SIZES[$set][$position];
        $width = 8 + ($halfWidth - 4.5) * 1.6;

        return [$width, $neck * $width / $halfWidth, $crownHeight, $rootLength, $roots, $edge];
    }

    private static function crown(float $w, float $n, float $cej, string $edge): string
    {
        $start = ['incisal' => 4, 'canine' => 9, 'premolar' => 7, 'molar' => 6][$edge];

        $occlusal = match ($edge) {
            'incisal' => sprintf('Q %s,0 %s,0 L %s,0 Q %s,0 %s,%s', self::n(-$w), self::n(-$w + 3), self::n($w - 3), self::n($w), self::n($w), self::n(-$start)),
            'canine' => sprintf('C %s,-5 -2,-0.6 1,0.8 C 3,-0.6 %s,-5 %s,%s', self::n(-$w * 0.7), self::n($w * 0.7), self::n($w), self::n(-$start)),
            'premolar' => sprintf('C %s,-2 -2,0.5 0,0.5 C 2,0.5 %s,-2 %s,%s', self::n(-$w * 0.8), self::n($w * 0.8), self::n($w), self::n(-$start)),
            default => sprintf(
                'C %1$s,-1.5 %2$s,0.8 %3$s,0.5 C %4$s,0.3 -1.5,-2.2 0,-2.4 C 1.5,-2.2 %5$s,0.3 %6$s,0.5 C %7$s,0.8 %8$s,-1.5 %8$s,%9$s',
                self::n(-$w), self::n(-$w * 0.7), self::n(-$w * 0.45), self::n(-$w * 0.2),
                self::n($w * 0.2), self::n($w * 0.45), self::n($w * 0.7), self::n($w), self::n(-$start),
            ),
        };

        return sprintf(
            'M %1$s,%2$s C %3$s,%4$s %5$s,%6$s %3$s,%7$s %8$s C %9$s,%6$s %10$s,%4$s %11$s,%2$s Q 0,%12$s %1$s,%2$s Z',
            self::n(-$n), self::n($cej),
            self::n(-$w), self::n($cej * 0.75),
            self::n(-$w - 0.5), self::n($cej * 0.4),
            self::n(-$start),
            $occlusal,
            self::n($w + 0.5), self::n($w), self::n($n),
            self::n($cej + 2.5),
        );
    }

    private static function singleRoot(float $n, float $cej, float $apex, float $length): string
    {
        // El ápice se inclina un poco hacia distal, como en la boca.
        $tip = -1.2;

        return sprintf(
            'M %1$s,%2$s C %1$s,%3$s %4$s,%5$s %6$s,%7$s C %8$s,%5$s %9$s,%3$s %9$s,%2$s Z',
            self::n(-$n), self::n($cej), self::n($cej - $length * 0.45),
            self::n($tip - 2.6), self::n($apex + 5), self::n($tip), self::n($apex),
            self::n($tip + 2.6), self::n($n),
        );
    }

    private static function twoRoots(float $n, float $cej, float $apex, float $furcation, float $length): string
    {
        return sprintf(
            'M %s,%s C %s,%s %s,%s %s,%s C %s,%s -2.2,%s 0,%s C 2.2,%s %s,%s %s,%s C %s,%s %s,%s %s,%s Z',
            self::n(-$n), self::n($cej),
            self::n(-$n - 0.6), self::n($cej - $length * 0.5), self::n(-$n * 0.95), self::n($apex + 7), self::n(-$n * 0.72 - 1), self::n($apex + 2),
            self::n(-$n * 0.45), self::n($apex + 5), self::n($furcation - 5), self::n($furcation),
            self::n($furcation - 5), self::n($n * 0.42), self::n($apex + 8), self::n($n * 0.7), self::n($apex + 4),
            self::n($n * 0.98), self::n($apex + 9), self::n($n + 0.6), self::n($cej - $length * 0.5), self::n($n), self::n($cej),
        );
    }

    /** La raíz palatina de los molares superiores: se ve detrás de las dos vestibulares. */
    private static function palatalRoot(float $furcation, float $apex): string
    {
        return sprintf(
            'M -3.5,%1$s C -3.6,%2$s -1.5,%3$s 0,%4$s C 1.5,%3$s 3.6,%2$s 3.5,%1$s Z',
            self::n($furcation + 2), self::n($apex + 6), self::n($apex - 1), self::n($apex - 2),
        );
    }

    /**
     * Los conductos, para el tratamiento pulpar: una línea por raíz.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function canals(int $roots, float $n, float $cej, float $apex, float $furcation, float $palatalApex): array
    {
        if ($roots === 1) {
            return [[0.0, round($cej + 4, 2), -1.2, round($apex + 4, 2)]];
        }

        $canals = [
            [round(-$n * 0.5, 2), round($cej + 3, 2), round(-$n * 0.72 - 0.6, 2), round($apex + 5, 2)],
            [round($n * 0.48, 2), round($cej + 3, 2), round($n * 0.7 - 0.4, 2), round($apex + 7, 2)],
        ];

        if ($roots === 3) {
            $canals[] = [0.0, round($furcation + 1, 2), 0.0, round($palatalApex + 1, 2)];
        }

        return $canals;
    }

    /** Un implante: el tornillo en lugar de la raíz. */
    private static function implant(float $n, float $cej, float $apex): string
    {
        return sprintf(
            'M %1$s,%2$s L %3$s,%4$s Q 0,%5$s %6$s,%4$s L %7$s,%2$s Z',
            self::n(-$n * 0.8), self::n($cej), self::n(-$n * 0.55), self::n($apex + 10), self::n($apex + 4), self::n($n * 0.55), self::n($n * 0.8),
        );
    }

    /**
     * Las roscas del implante.
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float}>
     */
    private static function threads(float $n, float $cej, float $apex): array
    {
        $threads = [];
        $top = $apex + 12;

        for ($y = $cej - 4; $y > $top; $y -= 4.5) {
            $half = $n * (0.55 + 0.25 * ($y - ($apex + 10)) / ($cej - ($apex + 10)));
            $threads[] = [round(-$half, 2), round($y + 1.2, 2), round($half, 2), round($y, 2)];
        }

        return $threads;
    }

    private static function n(float $value): string
    {
        $text = rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return $text === '-0' ? '0' : $text;
    }
}
