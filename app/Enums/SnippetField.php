<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Campo de la historia. */
enum SnippetField: string implements HasLabel
{
    case Any = 'any';
    case ChiefComplaint = 'chief_complaint';
    case ClinicalExam = 'clinical_exam';
    case Diagnosis = 'diagnosis';
    case Procedures = 'procedures';
    case Indications = 'indications';
    case Prescription = 'prescription';

    public function getLabel(): string
    {
        return match ($this) {
            self::Any => 'Cualquier campo',
            self::ChiefComplaint => 'Motivo de consulta',
            self::ClinicalExam => 'Examen clínico',
            self::Diagnosis => 'Diagnóstico',
            self::Procedures => 'Evolución (qué se hizo)',
            self::Indications => 'Indicaciones',
            self::Prescription => 'Receta',
        };
    }
}
