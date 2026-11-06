<?php

namespace Tests\Feature\Services\Clinical;

use App\Enums\TreatmentKind;
use App\Models\Patient;
use App\Models\Treatment;
use App\Services\Clinical\MedicationCheck;
use App\Services\Clinical\PediatricDose;
use App\Services\Clinical\TreatmentRisks;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

/** Lo que cambia según el paciente: la dosis de un niño, los avisos de la receta y lo que se revisa antes de empezar. */
class PatientSafetyChecksTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_a_child_dose_goes_by_weight_and_never_passes_an_adult_one(): void
    {
        $this->assertSame(
            ['medication' => 'Ibuprofeno suspensión 100 mg/5 ml', 'instructions' => '9 ml (180 mg) cada 8 horas por 3 días, después de los alimentos. Calculado para 18 kg (30 mg/kg al día).'],
            PediatricDose::item('ibuprofen', 18),
        );

        // Se mide con jeringa: hacia abajo, de medio en medio mililitro.
        $this->assertStringStartsWith('11 ml (264 mg) cada 6 horas', PediatricDose::item('paracetamol', 18)['instructions']);
        $this->assertStringStartsWith('20.5 ml (492 mg) cada 6 horas', PediatricDose::item('paracetamol', 45)['instructions']);
        $this->assertStringEndsWith('Dosis de adulto: con 45 kg ya no se calcula por peso.', PediatricDose::item('paracetamol', 45)['instructions']);
    }

    public function test_the_prescription_warns_by_age_pregnancy_illness_and_what_the_patient_takes(): void
    {
        $pregnant = $this->patient(30, ['is_pregnant' => true, 'pregnancy_weeks' => 24, 'conditions' => ['asthma']]);
        $this->assertSame(['Cuidado con Ibuprofeno: está embarazada de 24 semanas, y desde la semana 20 se evita; puede darle una crisis de asma.'], MedicationCheck::warnings($pregnant, 'Ibuprofeno 400 mg'));
        $this->assertSame([], MedicationCheck::warnings($pregnant, 'Paracetamol 500 mg'));
        $this->assertSame([], MedicationCheck::warnings($this->patient(30, ['is_pregnant' => true, 'pregnancy_weeks' => 12]), 'Ibuprofeno 400 mg'));
        $this->assertStringContainsString('no se da en el embarazo', MedicationCheck::warnings($pregnant, 'Doxiciclina 100 mg')[0]);

        $this->assertStringContainsString('no se da a menores de 12', MedicationCheck::warnings($this->patient(10), 'Tramadol 50 mg')[0]);
        $this->assertStringContainsString('no se da a menores de 16', MedicationCheck::warnings($this->patient(14), 'Aspirina 100 mg')[0]);
        $this->assertSame([], MedicationCheck::warnings($this->patient(30), 'Tramadol 50 mg'));

        $onWarfarin = $this->patient(70, ['medications' => 'Warfarina 5 mg', 'conditions' => ['kidney_disease', 'hepatitis']]);
        $this->assertSame(['Cuidado con Naproxeno: se evita con enfermedad renal; aumenta el sangrado con anticoagulantes o problemas de coagulación.'], MedicationCheck::warnings($onWarfarin, 'Naproxeno 550 mg'));
        $this->assertSame(['Cuidado con Metronidazol: aumenta el efecto de la warfarina que toma.'], MedicationCheck::warnings($onWarfarin, 'Metronidazol 500 mg'));
        $this->assertSame(['Cuidado con Paracetamol: con hepatitis, no más de 2 g al día.'], MedicationCheck::warnings($onWarfarin, 'Paracetamol 500 mg'));
        $this->assertSame([], MedicationCheck::warnings($onWarfarin, 'Amoxicilina 500 mg'));
    }

    public function test_before_starting_it_crosses_the_treatment_with_the_background(): void
    {
        $patient = $this->patient(68, ['medications' => 'Apixabán 5 mg', 'conditions' => ['hypertension', 'osteoporosis_meds'], 'smoker' => true]);

        $extraction = TreatmentRisks::for($patient, TreatmentKind::Extraction);
        $this->assertCount(4, $extraction);
        $this->assertStringStartsWith('Hipertensión: tómale la presión', $extraction[0]);
        $this->assertStringStartsWith('Anticoagulantes o coagulación', $extraction[1]);
        $this->assertStringStartsWith('Bifosfonatos', $extraction[2]);
        $this->assertSame('Fuma: más riesgo de alveolitis. Pídele que no fume al menos 72 horas.', $extraction[3]);
        $this->assertSame('Fuma: más riesgo de que el implante falle. Pídele que no fume al menos 72 horas.', TreatmentRisks::for($patient, TreatmentKind::Implant)[3]);

        // Una resina no sangra ni es cirugía: solo la presión antes de la anestesia.
        $this->assertCount(1, TreatmentRisks::for($patient, TreatmentKind::Filling));
        $this->assertSame([], TreatmentRisks::for($patient, TreatmentKind::Orthodontics));

        $this->assertSame(['Menor de 18: en general no se recomienda el blanqueamiento.', 'Es menor de edad: el consentimiento lo firma su apoderado.'], TreatmentRisks::for($this->patient(15), TreatmentKind::Whitening, hasConsent: true));
        $this->assertSame(['Tercer trimestre: atiéndela inclinada hacia la izquierda y en citas cortas.'], TreatmentRisks::for($this->patient(30, ['is_pregnant' => true, 'pregnancy_weeks' => 30]), TreatmentKind::Filling));
        $this->assertSame([], TreatmentRisks::for($this->patient(30, ['is_pregnant' => true, 'pregnancy_weeks' => 20]), TreatmentKind::Filling));
    }

    public function test_the_kind_of_treatment_comes_from_its_name(): void
    {
        $treatment = fn (string $name, bool $evaluation = false) => new Treatment(['name' => $name, 'slug' => str($name)->slug()->toString(), 'is_evaluation' => $evaluation]);
        $kind = fn (string $name, bool $evaluation = false) => TreatmentKind::for($treatment($name, $evaluation));

        $this->assertSame(TreatmentKind::Filling, $kind('Curación con resina'));
        $this->assertSame(TreatmentKind::Extraction, $kind('Cirugía de muela del juicio'));
        $this->assertSame(TreatmentKind::RootCanal, $kind('Endodoncia'));
        $this->assertSame(TreatmentKind::Orthodontics, $kind('Control de ortodoncia'));
        $this->assertSame(TreatmentKind::Whitening, $kind('Blanqueamiento'));
        $this->assertSame(TreatmentKind::Cleaning, $kind('Limpieza dental'));
        $this->assertSame(TreatmentKind::Evaluation, $kind('Primera visita', evaluation: true));
        $this->assertSame(TreatmentKind::Other, $kind('Urgencia'));

        $this->assertTrue(TreatmentKind::isControl($treatment('Control post extracción')));
        $this->assertFalse(TreatmentKind::isControl($treatment('Limpieza dental')));
        $this->assertTrue(TreatmentKind::isUrgent($treatment('Urgencia')));
        $this->assertSame('Pieza 36 · Caras MO · Color A2 · Con anestesia', TreatmentKind::Filling->summary(['tooth' => '36', 'surfaces' => 'MO', 'shade' => 'A2', 'anesthesia' => true, 'pulp_protection' => false]));
    }

    /** @param  array<string, mixed>  $background */
    private function patient(int $age, array $background = []): Patient
    {
        $patient = Patient::factory()->create(['birth_date' => now()->subYears($age)->subMonth()]);

        if ($background !== []) {
            $patient->medicalBackground()->create(['allergies' => 'Ninguna', ...$background]);
        }

        return $patient;
    }
}
