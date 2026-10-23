<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de diagnóstico. */
enum DiagnosisType: string implements HasLabel
{
    case Presumptive = 'presumptive';
    case Definitive = 'definitive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Presumptive => 'Presuntivo',
            self::Definitive => 'Definitivo',
        };
    }
}
