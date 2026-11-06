<?php

namespace App\Services\Clinical;

use App\Enums\TreatmentKind;
use App\Models\Patient;

/**
 * Lo que hay que revisar antes de empezar, cruzando el tratamiento de hoy con los antecedentes. Solo lo que
 * cambia algo hoy: una resina en alguien que toma anticoagulantes no lleva aviso; una extracción sí.
 * Son pautas generales de odontología: recuerdan, no deciden.
 */
final class TreatmentRisks
{
    /**
     * @param  bool  $hasConsent  El tratamiento tiene su consentimiento para firmar.
     * @return list<string>
     */
    public static function for(Patient $patient, TreatmentKind $kind, bool $hasConsent = false): array
    {
        $background = $patient->medicalBackground;
        $has = fn (string $condition): bool => in_array($condition, $background?->conditions ?? [], true);
        $age = $patient->age;
        $pregnant = (bool) $background?->is_pregnant;
        $weeks = $background?->pregnancy_weeks;
        $works = $kind->usesAnesthesia() || $kind === TreatmentKind::Cleaning;

        $heart = array_filter([
            $kind->bleeds() ? 'si tiene válvula artificial o tuvo endocarditis, necesita antibiótico antes de empezar' : null,
            $kind->usesAnesthesia() ? 'con epinefrina, hasta 2 cartuchos' : null,
        ]);

        return array_values(array_filter([
            $has('hypertension') && $kind->usesAnesthesia()
                ? 'Hipertensión: tómale la presión antes de la anestesia. Con 180/110 o más, posterga lo que pueda esperar.' : null,
            $has('heart_disease') && $heart !== []
                ? 'Corazón: '.implode('; ', $heart).'.' : null,
            $has('diabetes') && $kind->isSurgical()
                ? 'Diabetes: pregúntale si comió y cuál fue su última glucosa. Cicatriza más lento.' : null,
            MedicationCheck::takesAnticoagulants($patient) && $kind->bleeds()
                ? 'Anticoagulantes o coagulación: que no los deje sin hablar con su médico. Con warfarina, pide su INR reciente: con 3.5 o menos, por lo general se puede. Ten hemostático y sutura a mano.' : null,
            MedicationCheck::takesBoneDrugs($patient) && $kind->isSurgical()
                ? 'Bifosfonatos: el hueso puede no sanar (osteonecrosis). Explícaselo y que quede en el consentimiento.' : null,
            $background?->smoker && $kind->isSurgical()
                ? 'Fuma: '.($kind === TreatmentKind::Implant ? 'más riesgo de que el implante falle' : 'más riesgo de alveolitis').'. Pídele que no fume al menos 72 horas.' : null,
            $kind === TreatmentKind::Whitening && ($pregnant || $background?->is_breastfeeding)
                ? 'Embarazo o lactancia: posterga el blanqueamiento.' : null,
            $kind === TreatmentKind::Whitening && $age !== null && $age < 18
                ? 'Menor de 18: en general no se recomienda el blanqueamiento.' : null,
            $pregnant && $kind->usesAnesthesia() && $weeks !== null && $weeks >= 28
                ? 'Tercer trimestre: atiéndela inclinada hacia la izquierda y en citas cortas.' : null,
            $pregnant && $kind->usesAnesthesia() && ($weeks === null || $weeks < 14)
                ? 'Embarazada: lo que pueda esperar, mejor entre las semanas 14 y 27.' : null,
            $has('epilepsy') && $works
                ? 'Epilepsia: pregúntale si tomó su medicación hoy.' : null,
            $has('asthma') && $works
                ? 'Asma: que tenga su inhalador a mano.' : null,
            $has('anesthesia_reaction') && $kind->usesAnesthesia()
                ? 'Reacción a la anestesia: pregúntale qué pasó. Casi siempre fue un desmayo o palpitaciones por la epinefrina, no alergia.' : null,
            $hasConsent && $age !== null && $age < 18
                ? 'Es menor de edad: el consentimiento lo firma su apoderado.' : null,
        ]));
    }
}
