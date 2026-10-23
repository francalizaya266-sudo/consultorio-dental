<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Tipo de archivo. */
enum ClinicalFileType: string implements HasLabel
{
    case Xray = 'xray';
    case Photo = 'photo';
    case Consent = 'consent';
    case Document = 'document';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Xray => 'Radiografía',
            self::Photo => 'Foto clínica',
            self::Consent => 'Consentimiento',
            self::Document => 'Documento',
            self::Other => 'Otro',
        };
    }
}
