<?php

namespace App\Services\Clinical;

use App\Enums\TreatmentKind;
use App\Filament\Support\ClinicalAlerts;
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
                ? 'Bifosfonatos: el hueso puede no sanar (osteonecrosis). Explícaselo: su consentimiento ya lo dice.' : null,
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

    /**
     * Lo mismo, dicho para el consentimiento: los riesgos que corre este paciente por sus antecedentes, en palabras
     * que entiende quien firma (el paciente o su apoderado). Solo lo que cambia algo en este tratamiento.
     *
     * @return list<string>
     */
    public static function forConsent(Patient $patient, TreatmentKind $kind): array
    {
        $background = $patient->medicalBackground;
        $has = fn (string $condition): bool => in_array($condition, $background?->conditions ?? [], true);
        $works = $kind->usesAnesthesia() || $kind->bleeds() || $kind === TreatmentKind::Whitening;

        return array_values(array_filter([
            $works && ClinicalAlerts::meaningful($background?->allergies)
                ? 'Tiene alergia a: '.rtrim(trim((string) $background->allergies), '.').'. No se usará ningún material ni medicamento que la contenga.' : null,
            MedicationCheck::takesAnticoagulants($patient) && $kind->bleeds()
                ? 'Por los anticoagulantes o el problema de coagulación, puede sangrar más durante y después del tratamiento. No debe dejar sus medicamentos sin indicación de su médico.' : null,
            MedicationCheck::takesBoneDrugs($patient) && $kind->isSurgical()
                ? 'Por los bifosfonatos u otros medicamentos para los huesos, existe el riesgo de que el hueso no sane después del tratamiento (osteonecrosis de los maxilares).' : null,
            $has('diabetes') && $kind->isSurgical()
                ? 'Por la diabetes, la herida puede tardar más en sanar y hay más riesgo de infección.' : null,
            $background?->smoker && $kind->isSurgical()
                ? ($kind === TreatmentKind::Implant ? 'Por fumar, hay más riesgo de que el implante no se una al hueso.' : 'Por fumar, hay más riesgo de que la herida se infecte (alveolitis).').' Se recomienda no fumar al menos 72 horas.' : null,
            $has('hypertension') && $kind->usesAnesthesia()
                ? 'Por la presión alta, se le tomará la presión antes de la anestesia. Si está muy alta, el tratamiento se puede postergar.' : null,
            $has('heart_disease') && $kind->usesAnesthesia()
                ? 'Por la enfermedad del corazón, la anestesia se usará en dosis reducida y, si su médico lo indica, tomará antibiótico antes.' : null,
            $background?->is_pregnant && $kind->usesAnesthesia()
                ? 'Por el embarazo, se usarán solo anestesia y medicamentos compatibles, y lo que pueda esperar se puede postergar.' : null,
            $kind === TreatmentKind::Whitening && ($background?->is_pregnant || $background?->is_breastfeeding)
                ? 'Por el embarazo o la lactancia, se recomienda postergar el blanqueamiento.' : null,
        ]));
    }
}
