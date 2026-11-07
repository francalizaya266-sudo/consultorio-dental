<?php

namespace App\Services\Clinical;

use App\Models\Patient;
use Illuminate\Support\Str;

/**
 * Avisa si un medicamento de la receta no conviene por la edad, el embarazo, una enfermedad o lo que el
 * paciente ya toma. Las alergias las revisa AllergyCheck. Solo avisa: decide el doctor.
 */
final class MedicationCheck
{
    /** Antiinflamatorios (AINE). */
    private const NSAIDS = ['ibuprofen', 'naproxen', 'diclofenac', 'ketorolac', 'aspirin', 'acido acetilsalicilico', 'ketoprofen', 'dexketoprofen', 'piroxicam', 'meloxicam', 'nimesulid', 'celecoxib', 'etoricoxib'];

    private const ASPIRIN = ['aspirin', 'acido acetilsalicilico'];

    private const CHILD_OPIOIDS = ['codein', 'tramadol'];

    private const TETRACYCLINES = ['tetraciclin', 'doxiciclin', 'minociclin'];

    private const METRONIDAZOLE = ['metronidazol', 'tinidazol'];

    private const PARACETAMOL = ['paracetamol', 'acetaminofen'];

    /** Anticoagulantes y antiagregantes, como se suelen escribir en "medicamentos que toma". */
    private const ANTICOAGULANTS = ['warfarin', 'acenocumarol', 'apixaban', 'rivaroxaban', 'dabigatran', 'edoxaban', 'clopidogrel', 'prasugrel', 'ticagrelor', 'heparin', 'enoxaparin', 'aspirin', 'acido acetilsalicilico', 'anticoagul'];

    private const VITAMIN_K_ANTAGONISTS = ['warfarin', 'acenocumarol'];

    /** Para la presión, como se suelen escribir en "medicamentos que toma". */
    private const ANTIHYPERTENSIVES = ['losartan', 'valsartan', 'telmisartan', 'irbesartan', 'candesartan', 'enalapril', 'captopril', 'lisinopril', 'ramipril', 'amlodipin', 'nifedipin', 'hidroclorotiazid', 'clortalidon', 'furosemid', 'espironolacton', 'atenolol', 'propranolol', 'bisoprolol', 'carvedilol', 'metoprolol', 'nebivolol'];

    /** Para los huesos: con ellos, una extracción o un implante pueden no sanar (osteonecrosis). */
    private const BONE_DRUGS = ['alendronat', 'risedronat', 'ibandronat', 'zoledron', 'denosumab', 'bifosfonat'];

    /**
     * Avisos para un medicamento de la receta; vacío si no hay nada que decir.
     *
     * @return list<string>
     */
    public static function warnings(?Patient $patient, ?string $medication): array
    {
        if (! $patient || blank($medication)) {
            return [];
        }

        $background = $patient->medicalBackground;
        $conditions = $background?->conditions ?? [];
        $age = $patient->age;
        $pregnant = (bool) $background?->is_pregnant;
        $weeks = $background?->pregnancy_weeks;
        $is = fn (array $stems): bool => AllergyCheck::mentionsAny($medication, $stems);

        $reasons = [];

        if ($is(self::NSAIDS)) {
            if ($pregnant && ($weeks === null || $weeks >= 20)) {
                $reasons[] = $weeks ? "está embarazada de {$weeks} semanas, y desde la semana 20 se evita" : 'está embarazada, y desde la semana 20 se evita';
            }

            if (in_array('kidney_disease', $conditions, true)) {
                $reasons[] = 'se evita con enfermedad renal';
            }

            if (in_array('asthma', $conditions, true)) {
                $reasons[] = 'puede darle una crisis de asma';
            }

            if (self::takesAnticoagulants($patient)) {
                $reasons[] = 'aumenta el sangrado con anticoagulantes o problemas de coagulación';
            }
        }

        if ($is(self::ASPIRIN) && $age !== null && $age < 16) {
            $reasons[] = 'no se da a menores de 16';
        }

        if ($is(self::CHILD_OPIOIDS) && $age !== null && $age < 12) {
            $reasons[] = 'no se da a menores de 12';
        }

        if ($is(self::TETRACYCLINES) && ($pregnant || ($age !== null && $age < 8))) {
            $reasons[] = 'mancha los dientes que se están formando, no se da en el embarazo ni a menores de 8';
        }

        if ($is(self::METRONIDAZOLE) && AllergyCheck::mentionsAny($background?->medications, self::VITAMIN_K_ANTAGONISTS)) {
            $reasons[] = 'aumenta el efecto de la warfarina que toma';
        }

        if ($is(self::PARACETAMOL) && in_array('hepatitis', $conditions, true)) {
            $reasons[] = 'con hepatitis, no más de 2 g al día';
        }

        if ($reasons === []) {
            return [];
        }

        $drug = Str::of($medication)->trim()->before(' ')->ucfirst();

        return ["Cuidado con {$drug}: ".implode('; ', $reasons).'.'];
    }

    /** Si es un antiinflamatorio (AINE): para el dolor, el paracetamol suele ser la alternativa. */
    public static function isAntiInflammatory(?string $medication): bool
    {
        return AllergyCheck::mentionsAny($medication, self::NSAIDS);
    }

    /**
     * Cuidados que no impiden recetarlo pero conviene tener presentes: no se quita de una plantilla.
     *
     * @return list<string>
     */
    public static function cautions(?Patient $patient, ?string $medication): array
    {
        if (! $patient || blank($medication) || ! self::isAntiInflammatory($medication)) {
            return [];
        }

        $background = $patient->medicalBackground;
        $hypertensive = in_array('hypertension', $background?->conditions ?? [], true)
            || AllergyCheck::mentionsAny($background?->medications, self::ANTIHYPERTENSIVES);

        if (! $hypertensive) {
            return [];
        }

        $drug = Str::of($medication)->trim()->before(' ')->ucfirst();

        return ["{$drug} puede subirle la presión y restar efecto a su medicamento para la presión: mejor por pocos días."];
    }

    /** Toma anticoagulantes o tiene problemas de coagulación. */
    public static function takesAnticoagulants(Patient $patient): bool
    {
        $background = $patient->medicalBackground;

        return in_array('bleeding_disorder', $background?->conditions ?? [], true)
            || AllergyCheck::mentionsAny($background?->medications, self::ANTICOAGULANTS);
    }

    /** Toma bifosfonatos (o algo parecido para los huesos). */
    public static function takesBoneDrugs(Patient $patient): bool
    {
        $background = $patient->medicalBackground;

        return in_array('osteoporosis_meds', $background?->conditions ?? [], true)
            || AllergyCheck::mentionsAny($background?->medications, self::BONE_DRUGS);
    }
}
