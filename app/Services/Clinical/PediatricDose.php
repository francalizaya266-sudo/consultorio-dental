<?php

namespace App\Services\Clinical;

/**
 * Receta para niños: en jarabe y por su peso. La cuenta queda escrita (mg por kilo al día y los kilos)
 * para que el doctor la revise y la corrija si hace falta. Ninguna toma pasa de la dosis de un adulto.
 */
final class PediatricDose
{
    /**
     * Pautas generales de odontología pediátrica; el doctor las confirma en cada receta.
     *
     * @var array<string, array{label: string, medication: string, mgPerKgDay: int, mgPerMl: int, hours: int, days: int, maxDose: int, note: string}>
     */
    public const MEDICATIONS = [
        'ibuprofen' => ['label' => 'Ibuprofeno jarabe', 'medication' => 'Ibuprofeno suspensión 100 mg/5 ml', 'mgPerKgDay' => 30, 'mgPerMl' => 20, 'hours' => 8, 'days' => 3, 'maxDose' => 400, 'note' => 'después de los alimentos'],
        'paracetamol' => ['label' => 'Paracetamol jarabe', 'medication' => 'Paracetamol jarabe 120 mg/5 ml', 'mgPerKgDay' => 60, 'mgPerMl' => 24, 'hours' => 6, 'days' => 3, 'maxDose' => 500, 'note' => 'si hay dolor'],
        'amoxicillin' => ['label' => 'Amoxicilina jarabe', 'medication' => 'Amoxicilina suspensión 250 mg/5 ml', 'mgPerKgDay' => 50, 'mgPerMl' => 50, 'hours' => 8, 'days' => 7, 'maxDose' => 500, 'note' => 'sin dejarla aunque ya no duela'],
    ];

    /**
     * El renglón de la receta para ese peso: "9 ml (180 mg) cada 8 horas por 3 días…".
     *
     * @return array{medication: string, instructions: string}
     */
    public static function item(string $key, float $weightKg): array
    {
        $drug = self::MEDICATIONS[$key];
        $perDose = $drug['mgPerKgDay'] * $weightKg / (24 / $drug['hours']);
        $capped = $perDose > $drug['maxDose'];
        // Se mide con jeringa: hacia abajo, de medio en medio mililitro, para no pasarse.
        $ml = max(0.5, floor(min($perDose, $drug['maxDose']) / $drug['mgPerMl'] * 2) / 2);
        $mg = $ml * $drug['mgPerMl'];

        return [
            'medication' => $drug['medication'],
            'instructions' => self::number($ml).' ml ('.self::number($mg).' mg) cada '.$drug['hours'].' horas por '.$drug['days'].' días, '.$drug['note'].'. '
                .($capped
                    ? 'Dosis de adulto: con '.self::number($weightKg).' kg ya no se calcula por peso.'
                    : 'Calculado para '.self::number($weightKg).' kg ('.$drug['mgPerKgDay'].' mg/kg al día).'),
        ];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, '.', ''), '0'), '.');
    }
}
