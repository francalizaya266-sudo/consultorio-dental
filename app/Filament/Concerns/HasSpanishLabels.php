<?php

namespace App\Filament\Concerns;

use Illuminate\Support\Str;

/** En español solo va con mayúscula la primera palabra: "Bloqueos de horario", no "Bloqueos De Horario". */
trait HasSpanishLabels
{
    public static function getTitleCaseModelLabel(): string
    {
        return Str::ucfirst(static::getModelLabel());
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return Str::ucfirst(static::getPluralModelLabel());
    }
}
