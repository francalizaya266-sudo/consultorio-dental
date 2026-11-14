<?php

namespace App\Support;

use App\Models\Patient;
use Illuminate\Support\Str;

/**
 * Cómo llamar a cada persona de una familia sin confundirlas: por su primer nombre y, solo si
 * otra persona lo repite, con su primer apellido o con el nombre completo. Dos "María" pasan a
 * ser "María Quispe" y "María Flores"; si hasta ahí coinciden, "María Quispe Huamán".
 */
final class FamilyNames
{
    /**
     * @param  iterable<Patient>  $family
     * @return array<int, string> El nombre con que se muestra cada paciente, por su id.
     */
    public static function for(iterable $family): array
    {
        $names = [];

        foreach ($family as $patient) {
            $names[$patient->getKey()] = (string) $patient->first_name;
        }

        foreach (['short_name', 'full_name'] as $longer) {
            $timesUsed = array_count_values(array_map(self::comparable(...), $names));

            foreach ($family as $patient) {
                if ($timesUsed[self::comparable($names[$patient->getKey()])] > 1) {
                    $names[$patient->getKey()] = $patient->{$longer};
                }
            }
        }

        return $names;
    }

    /** Sin tildes ni mayúsculas: "MARIA" y "María" son el mismo nombre. */
    public static function comparable(string $name): string
    {
        return Str::lower(Str::ascii(Str::squish($name)));
    }
}
