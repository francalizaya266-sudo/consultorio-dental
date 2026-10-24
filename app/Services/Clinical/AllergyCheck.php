<?php

namespace App\Services\Clinical;

use App\Models\Patient;
use Illuminate\Support\Str;

/**
 * Avisa si un medicamento de la receta choca con una alergia registrada en los antecedentes
 * (por ejemplo, amoxicilina con alergia a la penicilina). Solo avisa: decide el doctor.
 */
final class AllergyCheck
{
    /**
     * Familias de medicamentos: lo que suele escribirse en la alergia y los medicamentos que la pueden dar.
     * Se compara sin tildes ni mayúsculas y por el comienzo de la palabra ("amoxicilin" vale para "amoxicilina").
     *
     * @var list<array{name: string, allergy: list<string>, drugs: list<string>, cross?: bool}>
     */
    private const FAMILIES = [
        ['name' => 'la penicilina', 'allergy' => ['penicilin', 'amoxicilin', 'ampicilin', 'betalactam'], 'drugs' => ['penicilin', 'amoxicilin', 'ampicilin', 'dicloxacilin', 'cloxacilin', 'oxacilin', 'bencilpenicilin', 'piperacilin']],
        ['name' => 'la penicilina', 'allergy' => ['penicilin', 'amoxicilin', 'betalactam'], 'drugs' => ['cefalexin', 'cefadroxil', 'cefuroxim', 'ceftriaxon', 'cefazolin', 'cefixim', 'cefaclor'], 'cross' => true],
        ['name' => 'las cefalosporinas', 'allergy' => ['cefalospor', 'cefalexin', 'cefadroxil', 'cefuroxim', 'ceftriaxon'], 'drugs' => ['cefalexin', 'cefadroxil', 'cefuroxim', 'ceftriaxon', 'cefazolin', 'cefixim', 'cefaclor']],
        ['name' => 'los antiinflamatorios (AINE)', 'allergy' => ['aine', 'antiinflamatori', 'ibuprofen', 'aspirin', 'acido acetilsalicilico', 'naproxen', 'diclofenac', 'ketorolac', 'ketoprofen'], 'drugs' => ['ibuprofen', 'naproxen', 'diclofenac', 'ketorolac', 'aspirin', 'acido acetilsalicilico', 'ketoprofen', 'dexketoprofen', 'piroxicam', 'meloxicam', 'nimesulid', 'celecoxib', 'etoricoxib']],
        ['name' => 'el metamizol', 'allergy' => ['metamizol', 'dipiron'], 'drugs' => ['metamizol', 'dipiron']],
        ['name' => 'el paracetamol', 'allergy' => ['paracetamol', 'acetaminofen'], 'drugs' => ['paracetamol', 'acetaminofen']],
        ['name' => 'las sulfas', 'allergy' => ['sulfa'], 'drugs' => ['sulfametoxazol', 'cotrimoxazol', 'sulfadiazin']],
        ['name' => 'los macrólidos', 'allergy' => ['macrolid', 'eritromicin', 'azitromicin', 'claritromicin'], 'drugs' => ['eritromicin', 'azitromicin', 'claritromicin']],
        ['name' => 'la clindamicina', 'allergy' => ['clindamicin', 'lincomicin'], 'drugs' => ['clindamicin', 'lincomicin']],
        ['name' => 'el metronidazol', 'allergy' => ['metronidazol', 'tinidazol'], 'drugs' => ['metronidazol', 'tinidazol']],
        ['name' => 'los opioides', 'allergy' => ['opioid', 'tramadol', 'codein', 'morfin'], 'drugs' => ['tramadol', 'codein', 'morfin', 'oxicodon']],
        ['name' => 'la anestesia local', 'allergy' => ['anestesi', 'lidocain', 'mepivacain', 'articain', 'prilocain'], 'drugs' => ['lidocain', 'mepivacain', 'articain', 'prilocain', 'bupivacain', 'benzocain']],
        ['name' => 'la clorhexidina', 'allergy' => ['clorhexidin'], 'drugs' => ['clorhexidin']],
        ['name' => 'el yodo', 'allergy' => ['yodo', 'povidon'], 'drugs' => ['povidon', 'yodo']],
    ];

    /** Aviso para un medicamento de la receta, o null si no choca con lo registrado. */
    public static function warning(?Patient $patient, ?string $medication): ?string
    {
        return self::warningFor($patient?->medicalBackground?->allergies, $medication);
    }

    public static function warningFor(?string $allergies, ?string $medication): ?string
    {
        $allergyWords = self::words($allergies);
        $drugWords = self::words($medication);

        if ($allergyWords === [] || $drugWords === []) {
            return null;
        }

        foreach (self::FAMILIES as $family) {
            if (! self::mentions($allergyWords, $family['allergy']) || ! self::mentions($drugWords, $family['drugs'])) {
                continue;
            }

            $drug = Str::of((string) $medication)->trim()->before(' ')->ucfirst();

            return ($family['cross'] ?? false)
                ? "Cuidado con {$drug}: puede dar reacción cruzada con su alergia a {$family['name']}."
                : "Cuidado con {$drug}: el paciente tiene alergia a {$family['name']}.";
        }

        return null;
    }

    /**
     * Avisos de toda la receta.
     *
     * @param  list<array{medication?: string|null}>  $items
     * @return list<string>
     */
    public static function warningsForItems(?Patient $patient, array $items): array
    {
        return collect($items)
            ->map(fn (array $item) => self::warning($patient, $item['medication'] ?? null))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @return list<string> */
    private static function words(?string $text): array
    {
        $normalized = Str::of((string) $text)->lower()->ascii()->replaceMatches('/[^a-z0-9]+/', ' ')->trim()->toString();

        return $normalized === '' ? [] : explode(' ', $normalized);
    }

    /**
     * @param  list<string>  $words
     * @param  list<string>  $stems
     */
    private static function mentions(array $words, array $stems): bool
    {
        $text = ' '.implode(' ', $words);

        foreach ($stems as $stem) {
            if (str_contains($text, ' '.$stem)) {
                return true;
            }
        }

        return false;
    }
}
