<?php

namespace App\Enums;

/**
 * Por qué viene el paciente: decide cuánto se escribe de motivo y examen. Se escriben completos una vez
 * por problema, cuando se diagnostica; después, cada sesión o control se registra con su evolución.
 */
enum VisitType: string
{
    /** Evaluación o primera consulta: motivo, examen, odontograma, diagnóstico y plan. */
    case Evaluation = 'evaluation';

    /** Viene a hacer algo de un plan armado en otra atención. */
    case PlanSession = 'plan_session';

    /** Control de algo que ya está en curso (ortodoncia, post-operatorio). */
    case Control = 'control';

    /** Sin cita, o una cita de urgencia: el motivo no puede faltar. */
    case Urgent = 'urgent';

    /** Viene por un tratamiento sin plan previo: hoy se diagnostica y se trata. */
    case FirstVisit = 'first_visit';

    /** Motivo y examen ya están en otra atención: se pliegan. */
    public function repeatsAnotherVisit(): bool
    {
        return in_array($this, [self::PlanSession, self::Control], true);
    }
}
